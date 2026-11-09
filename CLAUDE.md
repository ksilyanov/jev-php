# jev-php — minimal PHP client for Jev (TypeSafe)

Agent entry point. `AGENTS.md` points here. Read fully before changing anything; `docs/decisions.md` holds the why.

## Purpose & core principle

Give PHP apps **access to Jev and nothing more**: send `state` + typed questions → return answers → report failures so the caller can act.

**NEVER** add logic on top of Jev: no thresholds, caching, cost accounting, fallback models, question validation, logging/metrics hooks, retries beyond transport. Consumers own all policy. If a change makes the package *decide* something about the caller's domain, it does not belong here (D0).

**NEVER** add a second transport (e.g. direct TypeSafe API) without the owner: rejected in D13 until Cloudflare blocks something concrete (a pinned version, a missing Jev feature, rate limits). If asked, answer with D13 and ask the owner.

### Declined requests → where they go

| Request | Answer |
|---|---|
| cost in USD per call | consumer: `inputTokens` × its own price table (Cloudflare = $0.042/M input, output free) |
| exact spend incl. failed/retried attempts | AI Gateway analytics (`JEV_CLOUDFLARE_GATEWAY_ID`, D9) — the client cannot know it |
| a provider field the package does not model | `Response::$raw`. Passing a provider-reported value through as a new **nullable** field is allowed; computing one is not |
| "stop the run on X" flag / new exception kind | map X to one of the four kinds below by what the caller must do |
| retries/caching/thresholds | consumer code |

## Stack

PHP 8.2+ · Guzzle 7.8+ · PHPUnit 11 · optional Laravel 11/12 (`illuminate/support`). Consumers: Laravel 12 apps, Laravel Zero 11 CLIs (Laravel Zero ignores package discovery → register the provider in `config/app.php`).

## Map

| Path | Role |
|---|---|
| `src/Contracts/Jev.php` | THE public contract: `evaluate(string\|array $state, array<string,Question> $questions): Response`, `model(): string` |
| `src/Question.php` | `noul()` / `choice()` / `score()` builders in Jev's own vocabulary; `toWire()` = exact JSON shape |
| `src/Answer.php` | one class for all three answer types; nullable typed fields + `raw` |
| `src/Response.php` | answers by name + `model` (exact answering version or null), `driver`, tokens (final attempt, or null), `latencyMs` (whole call), `attempts`, `requestId`, `raw`. `answer($name)` throws `JevInvalidResponseException` if that name is missing (completeness is NOT checked in `evaluate()`) |
| `src/Drivers/CloudflareDriver.php` | the only transport, by decision (D13): Cloudflare Workers AI `POST /accounts/{id}/ai/run` |
| `src/Http/RetryPolicy.php` | backoff + `retry-after-ms` / `Retry-After` parsing (SDK defaults) |
| `src/Http/Failure.php` | HTTP status → failure kind (driver-agnostic fallback after provider codes) |
| `src/Http/RetryableUnavailable.php` | `@internal` — carries the PSR response to the retry loop; must never escape `evaluate()` |
| `src/Exceptions/*` | `JevException` base + 4 kinds (table below) |
| `src/Testing/FakeJev.php` | consumer test double: queued wire-form answers / exceptions, records `calls`. Reports `inputTokens`/`outputTokens` = 0, `model` = `answeringModel` (default `fake-jev-1.0.0`); empty queue → base `JevException` |
| `src/Laravel/JevServiceProvider.php` + `config/jev.php` | binds `Contracts\Jev` from env; unknown `JEV_DRIVER` → `InvalidArgumentException` at resolve time |
| `bin/smoke` | LIVE check against real Jev (manual, costs money; prints results, saves nothing) |
| `bin/test-lowest` | unit tests on PHP 8.2 + lowest deps in Docker |
| `tests/fixtures/*.json` | response bodies copied from live calls on 2026-09-28 — never hand-edit to make a test pass |

## Call flow

**Every driver MUST** (today implemented privately in `CloudflareDriver`; a second driver extracts this loop into `src/Http/` first):
1. empty credentials → `JevAccessException`, no request;
2. encode with `JSON_THROW_ON_ERROR` before sending; failure → `JevRequestRejectedException`, no request;
3. budget loop: attempt timeout = remaining of `JEV_TIMEOUT` (connect ≤ 10 s); only Unavailable is retried; a retry whose delay would exceed the budget is not started; give up as plain `JevUnavailableException` (`withoutResponse()`);
4. transport error (Guzzle `TransferException`) → Unavailable **without `previous`** (Guzzle exceptions hold the request incl. the Authorization header);
5. scrub token and account id from every message and `body` (`scrub()`);
6. classify: provider code map first, then `Failure::forStatus()`;
7. answer-parse errors rethrown as `JevInvalidResponseException` WITH `status`, `requestId`, `body`, `driver`;
8. missing `model` / `usage` → `null`, never invented.

**Cloudflare specifics:** codes in `CloudflareDriver::CODES` (2021, 3036 → Access; 3040 → Unavailable); `{"success": false}` on 2xx with unknown code → InvalidResponse; output unwrapped from nested `{"result": …}` envelopes; `requestId` = `cf-ray`; optional `cf-aig-gateway-id` header.

## Failure kinds — the contract consumers rely on

| Class | What the caller should do | Triggers |
|---|---|---|
| `JevUnavailableException` | try later / per-item fallback | Guzzle transfer error, 408, 429, 5xx, CF 3040, CF 2002 (known misfit: deterministic for structured score levels) |
| `JevRequestRejectedException` | skip this item, others may pass | 400, 413, 422, other 4xx, unencodable JSON |
| `JevAccessException` | stop the run: every call fails until something outside the request changes | 401, 402, 403, 404 (model), CF 2021, CF 3036, missing creds |
| `JevInvalidResponseException` | unknown scope — treat as per-item failure, alert if it repeats | 2xx non-JSON, 2xx `success:false` with unknown code, no `answers`, unknown answer type, missing value field, `answer($name)` for a missing name |

Context fields (`status`, `providerCode`, `requestId`, `body`, `driver`) are filled when known and may be null (transport errors have no status).

- **ALWAYS** check provider error code before HTTP status (CF 3036 and 3040 are both HTTP 429 with opposite meaning).
- **NEVER** add a fifth kind, an exception subclass, or a flag like "should stop". Map new provider conditions to one of the four by what the caller must do.
- **Moving or adding a trigger** = update together: README failures table, this table, `failures()` data provider in `tests/CloudflareDriverTest.php`, `@throws` in `src/Contracts/Jev.php`, docblock of each affected `src/Exceptions/*` class. Consumers branch on the class (e.g. a batch job aborts on Access and falls back per item on the rest).

## Rules

- **NEVER** put credentials into exception message, `body` or `previous`. Keep `test_token_never_leaks_into_exceptions`, `test_account_id_never_leaks_into_exceptions`, `test_transport_failure_does_not_chain_the_request` green.
- **ALWAYS** encode maps the package builds as JSON objects (`(object)`): questions map, choice criteria, noul criteria; score levels → `array_values` list. PHP turns `["0"=>…]` and `[]` into lists otherwise → 422. Caller-supplied structured `state`/instructions/descriptions are sent as given — document, don't transform.
- **ALWAYS** keep `model()` = configured/requested model (consumers key caches on it before calling). Each driver has its own `DEFAULT_MODEL` in its provider's vocabulary; `JEV_MODEL` is interpreted by the active driver; switching `JEV_DRIVER` changes `model()` — tell consumers it is a model change (cache keys, thresholds).
- **PREFER** fixtures copied from live calls; name them `<driver>-<case>.json`. A failure seen only in production goes in as a test built with the error helpers plus a fact line "source: prod log <date>, requestId <id>" — never as a hand-made "real" fixture.
- **NEVER** call real Jev from `tests/` — network only in `bin/smoke`. Say the cost before running smoke.
- Env names are prefixed `JEV_*` on purpose (D8).
- Public repo: no account ids, private project names, or owner email in tracked files; commits use the GitHub noreply author. Internal notes live in gitignored `docs/internal/`.

## Cloudflare facts (verified live 2026-09-28; re-verify with `bin/smoke` before relying on them)

- Only latest `typesafe/jev` is served; pinned `typesafe/jev-1.13` and `typesafe/jev-1.13.0` → 404, code 7003. Watch `Response::$model`.
- Output nested as `result.result.{model,answers,usage}` plus `result.state: "Completed"`.
- Structured score levels (objects) → 500, code 2002 "Failed to parse model output" → classified Unavailable and retried; use string levels. Structured instructions and structured choice descriptions work.
- Bad token → 401, code 10000. No balance → code 2021. Price equals TypeSafe direct ($0.042/M input, output free; checked in AI Gateway logs).
- Latency (60 sequential calls, 3 questions): p50 384 ms, p95 587 ms, 0 failures.
- NOT verified live: that `cf-aig-gateway-id` routes `/ai/run` through the named gateway (only that the header is sent).

## Commands

| Command | What |
|---|---|
| `composer install` | deps (dev: phpunit, illuminate/config/container/support, phpdotenv) |
| `vendor/bin/phpunit` | unit tests, no network |
| `bin/test-lowest` | same on PHP 8.2 with `--prefer-lowest` (Docker) |
| `bin/smoke [N]` | live: 3 question types, bad token, pinned model, optional N-call latency batch. Needs `JEV_CLOUDFLARE_ACCOUNT_ID` + `JEV_CLOUDFLARE_API_TOKEN` in env or `.env` (gitignored). Costs fractions of a cent |

## Release

Semver tags `vX.Y.Z` on `main`; consumers use a `vcs` repository + `^0.1` (= `>=0.1.0 <0.2.0`).
- patch: docs, fixes, classifying a previously unmapped provider code, new nullable pass-through field → reaches `^0.1` consumers automatically;
- minor (while 0.x): moving an already documented trigger to another class, any signature change → does NOT reach `^0.1`; tell each consumer to update its constraint.

Before tagging: `vendor/bin/phpunit` (8.3) and `bin/test-lowest` (8.2) green, `bin/smoke` green, README + this file match the code.

## Definition of Done

- [ ] tests green; new behaviour has a test (failure paths included)
- [ ] contract change reflected in README (user-facing), this file (agent-facing), `docs/decisions.md` if it is a decision
- [ ] no new logic beyond access (re-read "Core principle")
- [ ] nothing secret/private in the diff
