# Build status

What is built, what is tested, what is not. Kept honest; update it with the code.

## Built and tested

- Publishing with idempotency, catalog validation, payload cap, after-commit jobs.
- Endpoint registry with scope isolation, URL/header/scheme/bounds policy, wide
  endpoints behind `allow_wide_endpoints`, rotation with overlap, scheme change, ping,
  replay, health.
- Delivery: claim race, retry schedule and exhaustion, jitter, 4xx/5xx/timeout/
  connection classification, 410, 429 with `Retry-After` (seconds, HTTP date, cap),
  blocked-URL policy retries, circuit breaker (trip, wait without attempt, single probe,
  doubling cooldown with cap, recovery), rate limit, auto-disable (settlement and sweep),
  deleted-endpoint and pruned-event terminalisation.
- Batching: size, window, failure as a whole, one attempt row per item, idempotent
  grouping, multi-batch backlog, standard-webhooks id, stranded rescue.
- Sweep: orphans, stranded pending and in-flight, lease, limit, across scopes, paused
  and batching endpoints skipped.
- Monitor numbers, percentiles, health thresholds; prune by retention; erasure.
- Management API round trip, 404 across scopes, 422 shapes, missing resolver.
- Receiver: 202, duplicates, batches, inline failure, queued job, gaps, 401, 400,
  misconfigured secret 500.
- Telemetry adapters: counters, histograms, gauges, spans, `traceparent` both ways,
  telemetry events, disabled fallback, custom metrics class.
- The eight commands, the scheduler registration, the fake, the testing trait.

121 tests, 904 assertions, including the architecture test. SQLite in memory; PHPStan level
max with no ignores; Pint.

## Not built

- No UI.
- No per-item acknowledgement inside a batch.
- No mTLS, OAuth or IP allow-listing towards receivers.
- Schemes beyond `cbox` and `standard-webhooks` are not offered by the shipped form
  request; see docs/extension-points/signature-schemes.md.
- Not yet run against Postgres or MySQL in CI; the row-locking and conditional-update
  paths are written for them but exercised on SQLite only.
