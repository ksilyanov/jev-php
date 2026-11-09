<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Ksilyanov\Jev\Drivers\CloudflareDriver;
use Ksilyanov\Jev\Exceptions\JevAccessException;
use Ksilyanov\Jev\Exceptions\JevException;
use Ksilyanov\Jev\Exceptions\JevInvalidResponseException;
use Ksilyanov\Jev\Exceptions\JevRequestRejectedException;
use Ksilyanov\Jev\Exceptions\JevUnavailableException;
use Ksilyanov\Jev\Http\RetryPolicy;
use Ksilyanov\Jev\Question;
use PHPUnit\Framework\TestCase;

final class CloudflareDriverTest extends TestCase
{
    private const TOKEN = 'secret-token-123';

    private array $history = [];
    /** @var list<float> */
    private array $sleeps = [];
    private float $now = 0.0;

    private function driver(array $responses, float $timeout = 30.0, int $retries = 2, ?string $gateway = null, string $token = self::TOKEN): CloudflareDriver
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new CloudflareDriver(
            accountId: 'acc',
            apiToken: $token,
            timeout: $timeout,
            retry: new RetryPolicy(maxRetries: $retries, jitter: 0.0),
            gatewayId: $gateway,
            http: new Client(['handler' => $stack]),
            sleep: function (float $s): void {
                $this->sleeps[] = $s;
                $this->now += $s;
            },
            clock: fn (): float => $this->now,
        );
    }

    private static function json(int $status, array|string $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'] + $headers, is_string($body) ? $body : json_encode($body));
    }

    private static function fixture(string $name, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json', 'cf-ray' => 'ray-1'], file_get_contents(__DIR__ . "/fixtures/{$name}.json"));
    }

    private static function cfError(int $status, int $code, string $message = 'x'): HttpResponse
    {
        return self::json($status, ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'result' => []]);
    }

    private static function questions(): array
    {
        return [
            'live' => Question::noul('Is this a live music performance?'),
            'cat' => Question::choice('Category', ['0' => 'concert', '1' => null]),
            'hype' => Question::score('How big is the event?', ['small', 'medium', 'big']),
        ];
    }

    private function sentBody(int $i = 0): array
    {
        return json_decode((string) $this->history[$i]['request']->getBody(), true);
    }

    public function test_sends_documented_request(): void
    {
        $this->driver([self::fixture('cloudflare-ok')])->evaluate(['title' => 'Top DJ'], self::questions());

        /** @var Request $request */
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.cloudflare.com/client/v4/accounts/acc/ai/run', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('cf-aig-gateway-id'));

        $raw = (string) $request->getBody();
        $this->assertStringContainsString('"criteria":{"0":"concert","1":null}', $raw, 'choice options must stay a JSON object');
        $this->assertStringContainsString('"criteria":["small","medium","big"]', $raw);
        $body = $this->sentBody();
        $this->assertSame('typesafe/jev', $body['model']);
        $this->assertSame(['title' => 'Top DJ'], $body['input']['state']);
        $this->assertSame(['type' => 'noul', 'instructions' => 'Is this a live music performance?'], $body['input']['questions']['live']);
    }

    public function test_numeric_question_names_stay_an_object(): void
    {
        $this->driver([self::fixture('cloudflare-ok')])->evaluate('x', [Question::noul('a'), Question::noul('b')]);

        $this->assertStringContainsString('"questions":{"0":', (string) $this->history[0]['request']->getBody());
    }

    public function test_gateway_header_when_configured(): void
    {
        $this->driver([self::fixture('cloudflare-ok')], gateway: 'parser')->evaluate('x', self::questions());

        $this->assertSame('parser', $this->history[0]['request']->getHeaderLine('cf-aig-gateway-id'));
    }

    public function test_parses_real_cloudflare_response(): void
    {
        $response = $this->driver([self::fixture('cloudflare-ok')])->evaluate('x', self::questions());

        $this->assertSame('jev-1.13.0', $response->model);
        $this->assertSame('cloudflare', $response->driver);
        $this->assertSame(353, $response->inputTokens);
        $this->assertSame(62, $response->outputTokens);
        $this->assertSame(1, $response->attempts);
        $this->assertSame('ray-1', $response->requestId);
        $this->assertSame('Completed', $response->raw['result']['state']);

        $this->assertEqualsWithDelta(0.95, $response->answer('live')->noul, 1e-9);
        $cat = $response->answer('cat');
        $this->assertSame('0', $cat->choice);
        $this->assertEqualsWithDelta(0.85, $cat->confidence, 1e-9);
        $this->assertEqualsWithDelta(0.93, $cat->probabilityOf('0'), 1e-9);
        $hype = $response->answer('hype');
        $this->assertEqualsWithDelta(1.96, $hype->score, 1e-9);
        $this->assertEqualsWithDelta(0.97, $hype->probabilityOf(2), 1e-9);
        $this->assertSame('big', $hype->legend['2']);
    }

    public function test_unwrapped_output_and_missing_metadata_is_null_not_invented(): void
    {
        $response = $this->driver([self::json(200, ['answers' => ['live' => ['type' => 'noul', 'noul' => 0.1]]])])
            ->evaluate('x', self::questions());

        $this->assertNull($response->model);
        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
        $this->assertSame('typesafe/jev', $this->driver([])->model(), 'model() is the requested model');
    }

    public function test_retries_transient_failure_with_backoff(): void
    {
        $response = $this->driver([self::json(503, 'busy'), self::json(502, 'busy'), self::fixture('cloudflare-ok')])
            ->evaluate('x', self::questions());

        $this->assertSame(3, $response->attempts);
        $this->assertSame([0.5, 1.0], $this->sleeps);
        $this->assertSame(1500, $response->latencyMs);
    }

    public function test_honours_retry_after_headers(): void
    {
        $this->driver([
            self::json(429, 'slow down', ['Retry-After' => '2']),
            self::json(429, 'slow down', ['retry-after-ms' => '250']),
            self::fixture('cloudflare-ok'),
        ])->evaluate('x', self::questions());

        $this->assertSame([2.0, 0.25], $this->sleeps);
    }

    public function test_gives_up_after_max_retries_as_plain_unavailable(): void
    {
        try {
            $this->driver([
                new ConnectException('timeout', new Request('POST', '/')),
                self::json(500, 'err'),
                self::json(529, 'overloaded', ['cf-ray' => 'ray-9']),
            ])->evaluate('x', self::questions());
            $this->fail('expected unavailable');
        } catch (JevUnavailableException $e) {
            $this->assertSame(JevUnavailableException::class, $e::class);
            $this->assertSame(529, $e->status);
            $this->assertSame('ray-9', $e->requestId);
            $this->assertSame('cloudflare', $e->driver);
            $this->assertCount(3, $this->history);
        }
    }

    public function test_no_retry_that_would_exceed_the_budget(): void
    {
        try {
            $this->driver([self::json(429, 'x', ['Retry-After' => '20']), self::fixture('cloudflare-ok')], timeout: 10.0)
                ->evaluate('x', self::questions());
            $this->fail('expected unavailable');
        } catch (JevUnavailableException) {
            $this->assertSame([], $this->sleeps);
            $this->assertCount(1, $this->history);
        }
    }

    public function test_each_attempt_is_capped_by_remaining_budget(): void
    {
        $this->driver([self::json(503, 'x'), self::fixture('cloudflare-ok')], timeout: 10.0)->evaluate('x', self::questions());

        $this->assertEqualsWithDelta(10.0, $this->history[0]['options']['timeout'], 1e-9);
        $this->assertEqualsWithDelta(9.5, $this->history[1]['options']['timeout'], 1e-9);
    }

    /** @return iterable<string, array{HttpResponse, class-string<JevException>, bool}> */
    public static function failures(): iterable
    {
        yield 'account limited is 429 but permanent' => [self::cfError(429, 3036), JevAccessException::class, false];
        yield 'insufficient balance' => [self::cfError(400, 2021, 'Insufficient balance'), JevAccessException::class, false];
        yield 'model not found (real)' => [self::fixture('cloudflare-model-not-found', 404), JevAccessException::class, false];
        yield 'bad token' => [self::cfError(401, 10000, 'Authentication error'), JevAccessException::class, false];
        yield 'forbidden' => [self::json(403, 'no'), JevAccessException::class, false];
        yield 'payment required' => [self::json(402, 'pay'), JevAccessException::class, false];
        yield 'validation' => [self::json(422, 'bad question'), JevRequestRejectedException::class, false];
        yield 'too large' => [self::json(413, 'too large'), JevRequestRejectedException::class, false];
        yield 'other 4xx' => [self::json(400, 'bad'), JevRequestRejectedException::class, false];
        yield '200 but success false' => [self::cfError(200, 9999), JevInvalidResponseException::class, false];
        yield '200 but not json' => [new HttpResponse(200, [], '<html>oops</html>'), JevInvalidResponseException::class, false];
        yield '200 but no answers' => [self::json(200, ['success' => true, 'result' => ['state' => 'Completed']]), JevInvalidResponseException::class, false];
        yield 'unknown answer type' => [self::json(200, ['answers' => ['a' => ['type' => 'weird']]]), JevInvalidResponseException::class, false];
        yield 'out of capacity is retried' => [self::cfError(429, 3040), JevUnavailableException::class, true];
    }

    /** @dataProvider failures */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function test_failure_classification(HttpResponse $response, string $expected, bool $retried): void
    {
        $responses = $retried ? [$response, clone $response, clone $response] : [$response];
        try {
            $this->driver($responses)->evaluate('x', self::questions());
            $this->fail('expected ' . $expected);
        } catch (JevException $e) {
            $this->assertSame($expected, $e::class);
            $this->assertCount($retried ? 3 : 1, $this->history);
        }
    }

    public function test_unreadable_answer_keeps_request_context(): void
    {
        try {
            $this->driver([self::json(200, ['answers' => ['a' => ['type' => 'weird']]], ['cf-ray' => 'ray-7'])])->evaluate('x', self::questions());
            $this->fail('expected invalid response');
        } catch (JevInvalidResponseException $e) {
            $this->assertSame('ray-7', $e->requestId);
            $this->assertSame('cloudflare', $e->driver);
            $this->assertStringContainsString('weird', (string) $e->body);
        }
    }

    public function test_structured_legend_is_kept_as_sent(): void
    {
        $answer = ['type' => 'score', 'score' => 1.0, 'legend' => ['0' => ['label' => 'low'], '1' => 'high'], 'probabilities' => ['0' => 0.5, '1' => 0.5]];
        $response = $this->driver([self::json(200, ['answers' => ['s' => $answer]])])->evaluate('x', self::questions());

        $this->assertSame(['label' => 'low'], $response->answer('s')->legend['0']);
    }

    public function test_transport_failure_does_not_chain_the_request(): void
    {
        try {
            $this->driver([
                new ConnectException('down', new Request('POST', '/', ['Authorization' => 'Bearer ' . self::TOKEN])),
                new ConnectException('down', new Request('POST', '/')),
                new ConnectException('down', new Request('POST', '/')),
            ])->evaluate('x', self::questions());
            $this->fail('expected unavailable');
        } catch (JevUnavailableException $e) {
            $this->assertNull($e->getPrevious());
        }
    }

    public function test_unencodable_state_is_rejected_before_any_request(): void
    {
        $this->expectException(JevRequestRejectedException::class);
        try {
            $this->driver([])->evaluate("broken \xB1\x31 utf8", self::questions());
        } finally {
            $this->assertCount(0, $this->history);
        }
    }

    public function test_missing_credentials_is_access_error_before_any_request(): void
    {
        $this->expectException(JevAccessException::class);

        $this->driver([], token: '')->evaluate('x', self::questions());
    }

    public function test_token_never_leaks_into_exceptions(): void
    {
        try {
            $this->driver([self::json(400, ['success' => false, 'errors' => [['code' => 1, 'message' => 'bad header Bearer ' . self::TOKEN]]])])
                ->evaluate('x', self::questions());
            $this->fail('expected rejection');
        } catch (JevException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e->body);
        }
    }
}
