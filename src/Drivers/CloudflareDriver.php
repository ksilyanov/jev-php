<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Ksilyanov\Jev\Answer;
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Exceptions\JevAccessException;
use Ksilyanov\Jev\Exceptions\JevException;
use Ksilyanov\Jev\Exceptions\JevInvalidResponseException;
use Ksilyanov\Jev\Exceptions\JevRequestRejectedException;
use Ksilyanov\Jev\Exceptions\JevUnavailableException;
use Ksilyanov\Jev\Http\Failure;
use Ksilyanov\Jev\Http\RetryableUnavailable;
use Ksilyanov\Jev\Http\RetryPolicy;
use Ksilyanov\Jev\Question;
use Ksilyanov\Jev\Response;

/**
 * Jev through Cloudflare Workers AI: POST /accounts/{id}/ai/run, body {model, input: {state, questions}}.
 * Billed from the account's AI Gateway credits. Keep gateway-level retries off — this client retries.
 */
final class CloudflareDriver implements Jev
{
    public const DRIVER = 'cloudflare';
    public const DEFAULT_MODEL = 'typesafe/jev';

    /** Cloudflare error codes that override the HTTP status. */
    private const CODES = [
        2021 => JevAccessException::class,      // insufficient AI Gateway balance
        3036 => JevAccessException::class,      // account limited (daily allocation), sent as 429
        3040 => JevUnavailableException::class, // out of capacity, sent as 429
    ];

    private readonly ClientInterface $http;
    private readonly \Closure $sleep;
    private readonly \Closure $clock;

    /**
     * @param float $timeout seconds for the whole call: every attempt and every wait between attempts
     * @param ?string $gatewayId AI Gateway to route through (cf-aig-gateway-id); null = account default
     * @param ?\Closure(float): void $sleep
     * @param ?\Closure(): float $clock monotonic seconds
     */
    public function __construct(
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly float $timeout = 30.0,
        private readonly RetryPolicy $retry = new RetryPolicy(),
        private readonly ?string $gatewayId = null,
        private readonly string $baseUrl = 'https://api.cloudflare.com/client/v4',
        ?ClientInterface $http = null,
        ?\Closure $sleep = null,
        ?\Closure $clock = null,
    ) {
        $this->http = $http ?? new Client();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function evaluate(string|array $state, array $questions): Response
    {
        if ($this->accountId === '' || $this->apiToken === '') {
            throw new JevAccessException('Cloudflare account id or API token is not configured', driver: self::DRIVER);
        }

        try {
            $body = json_encode([
                'model' => $this->model,
                'input' => [
                    'state' => $state,
                    'questions' => (object) array_map(static fn (Question $q) => $q->toWire(), $questions),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new JevRequestRejectedException('request is not encodable as JSON: ' . $e->getMessage(), driver: self::DRIVER, previous: $e);
        }

        $started = ($this->clock)();
        $attempt = 0;
        while (true) {
            $attempt++;
            $remaining = $this->timeout - (($this->clock)() - $started);
            try {
                [$data, $requestId] = $this->send($body, max(0.001, $remaining));

                return $this->toResponse($data, $requestId, $started, $attempt);
            } catch (RetryableUnavailable $e) {
                $delay = $this->retry->delay($attempt, $e->response);
                $elapsed = ($this->clock)() - $started;
                if ($attempt > $this->retry->maxRetries || $elapsed + $delay >= $this->timeout) {
                    throw $e->withoutResponse();
                }
                ($this->sleep)($delay);
            }
        }
    }

    /** @return array{0: array, 1: ?string} */
    private function send(string $body, float $timeout): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiToken, 'Content-Type' => 'application/json'];
        if ($this->gatewayId !== null && $this->gatewayId !== '') {
            $headers['cf-aig-gateway-id'] = $this->gatewayId;
        }

        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . "/accounts/{$this->accountId}/ai/run", [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout,
                'connect_timeout' => min(10.0, $timeout),
                'http_errors' => false,
            ]);
        } catch (TransferException $e) {
            // No `previous`: Guzzle exceptions hold the request, Authorization header included.
            throw new RetryableUnavailable($this->scrub('Cloudflare request failed: ' . $e::class . ': ' . $e->getMessage()), null, driver: self::DRIVER);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $requestId = $response->getHeaderLine('cf-ray') ?: null;
        $data = json_decode($raw, true);
        $errors = is_array($data) && is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $code = is_array($errors[0] ?? null) ? ($errors[0]['code'] ?? null) : null;

        $failed = $status >= 300 || (is_array($data) && ($data['success'] ?? true) === false);
        if ($failed) {
            $class = self::CODES[(int) $code] ?? ($status >= 300 ? Failure::forStatus($status) : JevInvalidResponseException::class);
            $message = $this->scrub(sprintf('Cloudflare HTTP %d%s: %s', $status, $code !== null ? " code {$code}" : '', self::describe($errors, $raw)));
            if ($class === JevUnavailableException::class) {
                throw new RetryableUnavailable($message, $response, $status, $code, $requestId, $this->scrub($raw), self::DRIVER);
            }
            throw new $class($message, $status, $code, $requestId, $this->scrub(mb_substr($raw, 0, 2000)), self::DRIVER);
        }

        if (!is_array($data)) {
            throw new JevInvalidResponseException('Cloudflare returned non-JSON', $status, null, $requestId, $this->scrub(mb_substr($raw, 0, 2000)), self::DRIVER);
        }

        return [$data, $requestId];
    }

    private function toResponse(array $data, ?string $requestId, float $started, int $attempts): Response
    {
        $raw = $data;
        // Cloudflare wraps the model output in one or more {"result": ...} envelopes.
        while (!isset($data['answers']) && is_array($data['result'] ?? null)) {
            $data = $data['result'];
        }
        if (!is_array($data['answers'] ?? null)) {
            throw new JevInvalidResponseException('response has no answers', 200, null, $requestId, $this->scrub(mb_substr(json_encode($raw) ?: '', 0, 2000)), self::DRIVER);
        }

        $answers = [];
        try {
            foreach ($data['answers'] as $name => $answer) {
                $answers[(string) $name] = Answer::fromWire((array) $answer);
            }
        } catch (JevInvalidResponseException $e) {
            throw new JevInvalidResponseException($e->getMessage(), 200, null, $requestId, $this->scrub(mb_substr(json_encode($raw) ?: '', 0, 2000)), self::DRIVER);
        }

        return new Response(
            answers: $answers,
            model: isset($data['model']) ? (string) $data['model'] : null,
            driver: self::DRIVER,
            inputTokens: isset($data['usage']['input_tokens']) ? (int) $data['usage']['input_tokens'] : null,
            outputTokens: isset($data['usage']['output_tokens']) ? (int) $data['usage']['output_tokens'] : null,
            latencyMs: (int) round((($this->clock)() - $started) * 1000),
            attempts: $attempts,
            requestId: $requestId,
            raw: $raw,
        );
    }

    private function scrub(string $text): string
    {
        return str_replace($this->apiToken, '[token]', $text);
    }

    private static function describe(array $errors, string $raw): string
    {
        if ($errors !== []) {
            return implode('; ', array_map(
                static fn ($e) => is_array($e) ? (string) ($e['message'] ?? json_encode($e)) : (string) $e,
                $errors,
            ));
        }

        return mb_substr($raw, 0, 300);
    }
}
