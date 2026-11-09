# jev-php

Minimal PHP client for [Jev](https://docs.typesafe.ai) — TypeSafe's structured evaluation model — with Laravel integration.

It does one thing: send a `state` and typed questions to Jev, return the answers, and report failures in a way your code can act on. No thresholds, caching, cost accounting or fallbacks — those belong to your application.

Transport today: **Cloudflare Workers AI** (`typesafe/jev`, billed from AI Gateway credits). The transport is selected by config, so switching it later does not change application code.

## Install

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/ksilyanov/jev-php" }],
"require": { "ksilyanov/jev-php": "^0.1" }
```

PHP 8.2+, Guzzle 7.8+. Laravel 11/12 optional.

## Configure

```dotenv
JEV_CLOUDFLARE_ACCOUNT_ID=...
JEV_CLOUDFLARE_API_TOKEN=...      # token with Workers AI permission only
JEV_CLOUDFLARE_GATEWAY_ID=        # optional: sends cf-aig-gateway-id (named AI Gateway; routing not yet verified live)
JEV_MODEL=                        # optional; Cloudflare serves only typesafe/jev (latest)
JEV_TIMEOUT=30                    # seconds for the whole call, retries and waits included
JEV_RETRIES=2
JEV_DRIVER=cloudflare
```

**Laravel** — the service provider is auto-discovered; `php artisan vendor:publish --tag=jev-config` to copy the config.
**Laravel Zero** ignores package discovery: add `Ksilyanov\Jev\Laravel\JevServiceProvider::class` to `config/app.php` providers.
**Plain PHP** — `new CloudflareDriver(accountId: ..., apiToken: ...)`.

## Use

```php
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Question;

$response = app(Jev::class)->evaluate(
    ['title' => 'Top DJ headline night at Savaya', 'location' => 'Uluwatu'],
    [
        'live'     => Question::noul('Is this a live music performance?'),
        'category' => Question::choice('Which category fits best?', [
            'concerts'      => 'a live act or DJ headline show',
            'entertainment' => 'parties, clubs, bars',
        ]),
        'size'     => Question::score('How big is the event?', ['small', 'medium', 'big']),
    ],
);

$response->answer('live')->noul;                       // 0.94 — probability of "yes"
$response->answer('category')->choice;                 // "concerts"
$response->answer('category')->confidence;             // 0.91
$response->answer('category')->probabilityOf('entertainment');
$response->answer('size')->score;                      // 1.93 (0..2)
$response->model;        // "jev-1.13.0" — exact version that answered (null if not reported)
$response->inputTokens;  // usage of the final attempt (null if not reported)
$response->latencyMs;    // whole call incl. retries
```

Instructions and option descriptions may be strings or structured arrays, as the API allows. Choice options are always sent as a JSON object and score levels as a list, so numeric keys are safe. Your own structured `state`, instructions and descriptions are sent as given — use `(object)` / `new \stdClass` where a map could be empty or 0-indexed. The client does not validate questions — the API does (422).

`$response->answer($name)` throws `JevInvalidResponseException` if Jev returned no answer under that name; `$response->answers` holds what came back.

`$jev->model()` returns the requested model before any call (e.g. for cache keys). Switching `JEV_DRIVER` or `JEV_MODEL` changes it — treat that as a model change (cache keys, tuned thresholds).

## Failures

Every failure from `evaluate()` is a `JevException`. `status`, `providerCode`, `requestId` (provider request id; Cloudflare: `cf-ray`), `body` and `driver` are filled when known and may be null (e.g. transport errors have no status). The API token and account id are scrubbed from messages and bodies, and transport exceptions are not chained. Misconfiguration (unknown `JEV_DRIVER`) throws `InvalidArgumentException` when the client is resolved.

| Exception | Meaning | Examples |
|---|---|---|
| `JevUnavailableException` | transient; retries exhausted | connection/timeout, 408, 429, 5xx, Cloudflare 3040 (out of capacity), Cloudflare 2002 (see below) |
| `JevRequestRejectedException` | this request was rejected; others may succeed | 400, 413, 422, other 4xx, state not encodable as JSON |
| `JevAccessException` | every call will fail until fixed | 401, 402, 403, 404 (model), Cloudflare 2021 (no balance), 3036 (account limit) |
| `JevInvalidResponseException` | provider answered with something unreadable | 2xx non-JSON, 2xx `success:false` with unknown code, no `answers`, unknown answer type, answer without its value field, `answer($name)` for a missing name |

Cloudflare error codes are checked before the HTTP status (3036 and 3040 both arrive as 429).

**Retries** follow TypeSafe's official SDKs: 2 retries, backoff 0.5 s doubling to 5 s with 25 % jitter, `Retry-After` / `retry-after-ms` honoured. No retry starts if it would exceed `JEV_TIMEOUT`; each attempt is capped by the time left. A retried call may be billed twice. Keep gateway-level retries off in AI Gateway and mind your queue's `tries` — otherwise retries multiply.

## Testing your code

```php
use Ksilyanov\Jev\Contracts\Jev;
use Ksilyanov\Jev\Exceptions\JevUnavailableException;
use Ksilyanov\Jev\Testing\FakeJev;

$jev = (new FakeJev(model: 'typesafe/jev'))
    ->push(['same' => ['type' => 'noul', 'noul' => 0.93]])
    ->pushException(new JevUnavailableException('down'));

$app->instance(Jev::class, $jev);
// ... $jev->calls holds every state/questions pair it received
```

`FakeJev` reports 0 tokens and `answeringModel` (default `fake-jev-1.0.0`) as `$response->model`; an empty queue throws a base `JevException`.

## Cloudflare: verified 2026-09-28

- `POST /accounts/{id}/ai/run`, body `{model: "typesafe/jev", input: {state, questions}}`; output nested in `result.result`.
- Pinned versions are **not** served (`typesafe/jev-1.13` and `typesafe/jev-1.13.0` → 404, code 7003). Only the latest model is available, so watch `$response->model` if you tuned thresholds against a version.
- Latency, 60 sequential calls with three questions: p50 384 ms, p95 587 ms, max 753 ms, 0 failures.
- Bad token → 401, code 10000.
- Structured instructions and structured choice descriptions work. Structured **score levels** (objects instead of strings) fail on Cloudflare with HTTP 500, code 2002 "Failed to parse model output" — it is reported as `JevUnavailableException` and retried, so use string levels.
- Not verified live: that `cf-aig-gateway-id` actually routes `/ai/run` through the named gateway.

Live check (manual, uses your credentials from env or `.env`, costs fractions of a cent): `bin/smoke [N]`. Unit tests on PHP 8.2 with lowest dependencies: `bin/test-lowest` (Docker).

## License

MIT
