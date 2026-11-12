# Build status

What is built, what is tested, what is not. Kept honest; update it with the code.

## Built and tested

- Publishing with idempotency, catalog validation, payload cap, after-commit jobs.
- Endpoint registry with scope isolation, URL/header/scheme/bounds policy, wide
  endpoints behind `allow_wide_endpoints`, rotation with overlap, scheme change, ping,
  replay, health.
- Scope matching: the four combinations of an owner endpoint and an event with and
  without a partition, and the fan-out query against `Scope::matches()` for every shape
  of scope (owner, partition-wide, global).
- A catalog per scope (`EventCatalogs`): the shared default, per-scope validation on
  publish, register and update, wildcards on fan-out and replay, the API and the
  console per scope, a scope with no catalog offering nothing.
- Scope on attempts: written for single, batched and rate-limited attempts, backfilled
  by the migration (down and up again over real rows), erased by scope.
- Cursor pagination of deliveries, attempts and events: no skips or repeats with tied
  timestamps, rows arriving mid-walk, every filter, a cursor used in another scope,
  invalid cursors, event lookup by id, the four API routes.
- Retention per scope: the config default, a shorter and a longer window per scope,
  field-by-field fallback, scopes with only events left, the floor of one day.
- Envelope formats per endpoint: the `cbox` bytes pinned against 0.1, CloudEvents 1.0
  single and batch with the signature verified over the recorded body, an empty payload
  as an object, a host-registered format, an unknown name refused on register, update
  and in the API, a retired name failing loudly without losing the delivery.
- Host seams: the user agent and fixed headers from config and per endpoint (a profile
  cannot spoof a signature or transport header), secrets through a host encrypter
  including rotation and reading 0.1 rows, every table and migration on a configured
  connection with nothing on the default one, the scope on spans only when asked and
  never on a metric.
- Delivery: claim race, retry schedule and exhaustion, jitter, 4xx/5xx/timeout/
  connection classification, 410, 429 with `Retry-After` (seconds, HTTP date, cap),
  blocked-URL policy retries, circuit breaker (trip, wait without attempt, single probe,
  doubling cooldown with cap, recovery), rate limit, auto-disable (settlement and sweep),
  deleted-endpoint and pruned-event terminalisation.
- Replay selection (0.3): named events however old, held to scope and subscriptions;
  undelivered only (skips delivered, pending, in flight and awaiting retry); the limit
  filled past unsubscribed rows; `countReplay` equal to what `replay` creates and
  writing nothing; pacing by `floor(i * 60 / perMinute)` with the sweep waiting for a
  paced row's due time; one flush for a batching endpoint; the API's `dry_run` and the
  command's `--dry-run`.
- Delivery policy per endpoint (0.3): own retry schedule (and `[]` as one try, also on a
  429), `Retry-After` stopping once the schedule is spent, own timeout passed to the
  client, bounds refused on register, update and in the API, clearing back to defaults.
- Redirects (0.3): 307 and 308 followed with the same method, body and signature;
  301/302/303 refused; three hops; every hop through the SSRF guard (private address and
  plain http refused) and pinned to its own addresses; a redirect without `Location`.
- Headers (0.3): values sealed through the bound encrypter with a `sealed:` mark,
  0.2 plaintext still read, the sealing migration up, again and down, masked in
  `toArray()`, reserved names and prefixes, token names, CR/LF, length, ten at most.
- Transforms (0.3): refused with no transformer bound, validated on register and
  update, the event as input, the result in `data` for `cbox` and `cloudevents` and as
  the body for `raw`, a throw or a null result exhausting with `transform_failed` and
  no retry, per event in a batch.
- The `raw` envelope (0.3): payload only, signed under both schemes, empty payload as
  `{}`, batch as an array.
- Batching: size, window, failure as a whole, one attempt row per item, idempotent
  grouping, multi-batch backlog, standard-webhooks id, stranded rescue.
- Sweep: orphans, stranded pending and in-flight, lease, limit, across scopes, paused
  and batching endpoints skipped. Fairness: a noisy and a quiet owner under oldest
  first, round-robin by scope and by partition, the next tick, the stranded rescue, an
  unknown setting.
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

249 tests, 1453 assertions, including the architecture test, on SQLite in memory and on
PostgreSQL 18 (one test needs a second connection and is skipped on SQLite). PHPStan
level max with no ignores; Pint.

To run the suite on PostgreSQL locally:
`WEBHOOKS_TEST_DB=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=webhooks DB_USERNAME=postgres DB_PASSWORD=… vendor/bin/pest`.

## Not built

- No UI.
- No total counts and no offset pages in the paginated listings; keyset only.
- No per-item acknowledgement inside a batch.
- The receiver parses the `cbox` envelope only; CloudEvents is a sending format.
- CloudEvents binary mode (`ce-*` headers) is not offered; structured mode only. The
  output is checked against the specification's required attributes by the tests, not
  by an external conformance suite.
- No mTLS, OAuth or IP allow-listing towards receivers.
- No expression language for transforms: the host binds a `PayloadTransformer`.
- A replay to a batching endpoint is not paced per delivery; its batch size and window
  pace it. A pacing delay longer than the queue allows (SQS: 15 minutes) falls back on
  the sweep after the due time plus `stranded_after_seconds`.
- Schemes beyond `cbox` and `standard-webhooks` are not offered by the shipped form
  request; see docs/extension-points/signature-schemes.md.
- Not run against MySQL or MariaDB in CI; the row-locking and conditional-update paths
  are exercised on SQLite and PostgreSQL only. The fair sweep's window expression has a
  backtick-quoted form for MySQL and MariaDB that no test executes.
