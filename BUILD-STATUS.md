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

- On PostgreSQL: the fan-out's row lock makes a second publisher wait (a real second
  connection), and a repeated idempotency key inside a host transaction leaves that
  transaction usable.

124 tests, 914 assertions, including the architecture test, on SQLite in memory and on
PostgreSQL 18 (one test needs a second connection and is skipped on SQLite). PHPStan
level max with no ignores; Pint.

To run the suite on PostgreSQL locally:
`WEBHOOKS_TEST_DB=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=webhooks DB_USERNAME=postgres DB_PASSWORD=… vendor/bin/pest`.

## Not built

- No UI.
- No per-item acknowledgement inside a batch.
- No mTLS, OAuth or IP allow-listing towards receivers.
- Schemes beyond `cbox` and `standard-webhooks` are not offered by the shipped form
  request; see docs/extension-points/signature-schemes.md.
- Not run against MySQL or MariaDB in CI; the row-locking and conditional-update paths
  are exercised on SQLite and PostgreSQL only.
