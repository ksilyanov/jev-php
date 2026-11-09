# Decisions

Why jev-php is shaped this way. Reviewed 2026-09-27 by an independent two-model panel (grounded + cold reviewer, cross-examined, chairman synthesis); revisions from that review are folded in below.

## D0 — Access only, no logic

The package sends state + questions, returns answers, reports failures. Thresholds, caching, cost, fallback models, question validation, telemetry hooks belong to consumers.
**Why:** several apps with different policies share it; any policy here is wrong for someone and makes a transport change touch consumer behaviour.
**Rejected:** client-side question validation (duplicates API rules that change, e.g. 255 options / 10 levels — the API answers 422 anyway); full response validation (re-implements the API contract); per-call telemetry callback (Response and exceptions already carry everything).

## D1 — One interface: `evaluate()` + `model()`

`model()` returns the requested model before any call, because consumers key caches on it. The exact answering version is `Response::$model`.

## D2 — Questions in Jev's vocabulary

`noul` / `choice` / `score`, instructions and descriptions `string|array` as the API allows. Builders only shape JSON (objects for maps, list for levels); they do not validate.

## D3 — One `Answer` class

Nullable typed fields + `raw`. Three classes would add API surface without adding safety; `type` discriminates.

## D4 — Four failure kinds (revised by review)

Originally two (transient / permanent). Review showed "permanent" mixed *this request is bad* (413/422, too-long state) with *everything is broken* (bad token, no balance) — a consumer aborting its whole run on "permanent" would die on one oversized item. Now: Unavailable, RequestRejected, Access, InvalidResponse — named by what the caller must do (retry later / skip item / stop run / unknown). Provider codes are checked before HTTP status (Cloudflare 3036 "account limited" and 3040 "out of capacity" are both 429). No fifth kind and no "should stop" flags: every new provider condition maps to one of the four.

## D5 — Unreadable response is its own kind

Not "global failure": a truncated body can be one-off, a format change is not — the package can't tell, so it reports the reason and leaves scope to the caller.

## D6 — Retries in the package, with a hard budget

TypeSafe SDK defaults (2 retries, 0.5 s ×2 up to 5 s, 25 % jitter, `Retry-After` / `retry-after-ms`). `JEV_TIMEOUT` bounds the whole call including waits; a retry that would exceed it is not started; each attempt gets the remaining time.
**Why not gateway retries:** they vanish if the transport changes, and stacked retries (queue × package × gateway) multiply cost. Keep AI Gateway retries off.

## D7 — Guzzle directly

Both consumer stacks already ship Guzzle 7; a PSR-18 discovery layer would be an extra abstraction with no consumer asking for it.

## D8 — `JEV_*` env names

`CLOUDFLARE_API_TOKEN` is a well-known name (wrangler); a broad token sitting there would be picked up silently.

## D9 — Optional AI Gateway id

`JEV_CLOUDFLARE_GATEWAY_ID` → `cf-aig-gateway-id`: intended for per-app analytics, limits and spend inside one Cloudflare account, without code here. Header sending is tested; routing effect not yet verified live.

## D10 — Laravel provider, framework optional

Auto-discovered in Laravel; Laravel Zero ignores discovery and registers it in `config/app.php`; plain PHP constructs the driver.

## D11 — `FakeJev` ships with the package

Otherwise every consumer writes its own double, and they drift from the real contract.

## D12 — Public GitHub + `vcs` repository, no Packagist

Installs in consumer Docker builds without credentials; no third-party listing to maintain.

## D13 — Cloudflare only; no direct TypeSafe driver (owner decision 2026-11-08)

Same price as direct. Direct would add version pinning (Cloudflare serves only latest), but that is a second integration to pay for and watch while no consumer has tuned thresholds. Mitigation on Cloudflare: consumers store `Response::$model` next to results and re-check thresholds when it changes. Revisit if Cloudflare blocks something concrete (need a pinned version, missing Jev feature, rate limits). Adding it then = extract the shared budget/retry/scrub loop into `src/Http/`, new driver class, provider `match` arm, `drivers.*` config block, smoke support, README/CLAUDE.md updates — see CLAUDE.md "Call flow".
