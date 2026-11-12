# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.3.0] - 2026-11-13

### Breaking

- **`Contracts\Endpoints` gains `countReplay(Scope $scope, string $id, ReplayRequest
  $request): int`.** Callers are unaffected. **Migration:** a class that implements or
  decorates `Endpoints` adds it (a decorator forwards it).
- **`ReplayRequest::$since` is nullable**, and the constructor refuses a replay bounded
  by neither `since` nor `eventIds` (`InvalidArgumentException`).
- **Header values are masked in `Endpoint::toArray()`** and so in the management API
  (`"Bearer…"`); the `headers` property keeps them opened.
- **Stricter header rules**: at most 10, values up to 1000 characters without CR, LF or
  NUL, token names, and `Content-Encoding` plus the `webhook-`, `x-webhook-`, `x-cbox-`
  and `ce-` prefixes reserved. Existing endpoints keep their headers until they are set
  again.
- **A 429 exhausts a delivery once its schedule is spent**, instead of retrying on
  `Retry-After` indefinitely.
- **The delivery job timeout is sized for the highest endpoint timeout**
  (`http.max_endpoint_timeout`, default 120 seconds). Lower it if your endpoints never
  need that long.

### Added

- Safer replay. `ReplayRequest` takes `eventIds` (those events only, however old, still
  held to the endpoint's scope and subscriptions; `ReplayRequest::events([...])`),
  `onlyUndelivered` (skip an event with a delivery to the endpoint that succeeded or is
  pending, in flight or awaiting a retry) and `perMinute` (delivery `i` is queued
  `floor(i * 60 / perMinute)` seconds out, and the sweep waits for that time).
  `Endpoints::countReplay()` answers what `replay()` would create without writing
  anything; both use one selection. The API's replay takes `event_ids`,
  `only_undelivered`, `per_minute` and `dry_run`; `webhooks:replay` takes `--event`,
  `--undelivered`, `--per-minute` and `--dry-run`, and `--since` is optional with
  `--event`.
- A delivery policy per endpoint: `retry_schedule` (a list of seconds; `[]` is one try),
  `timeout_seconds` and `follow_redirects`, on `NewEndpoint`, `EndpointChanges`
  (`clearRetrySchedule`, `clearTimeout`), the `Endpoint` value object and the API.
  Bounds: 20 entries of 1 to 604 800 seconds, a timeout of 1 to 120 seconds (or
  `http.max_endpoint_timeout`). The jitter stays the configured one.
- Redirect following, off by default: 307 and 308 only, at most three hops, the same
  method, body and signature; every hop's `Location` resolved against the current URL,
  held to https when required, validated by the SSRF guard and pinned like the first
  URL. 301, 302 and 303 fail with `redirect_refused`, a fourth hop with
  `too_many_redirects`. The timeout covers the whole chain.
- Endpoint header values sealed at rest with the bound `SecretEncrypter` (a `sealed:`
  mark; plain values from 0.2 are still read) and `Endpoint::maskedHeaders()`.
- `Contracts\PayloadTransformer` (`validate`, `apply`) and a `transform` per endpoint
  (up to 4000 characters, validated on register and update). The default binding
  refuses every expression; the package ships no expression language. The result
  replaces `data` in the `cbox` and `cloudevents` envelopes and is the body in `raw`. A
  `TransformFailed`, a null or a non-JSON result ends the delivery at once:
  `AttemptOutcome::TransformFailed` (`transform_failed`) on the attempt, reason
  `transform_failed`, no retry, no breaker failure; per event in a batch.
- The `raw` envelope format: the payload (or the transform's result) as the whole body;
  a batch is a JSON array of them.
- Testing: `Testing\FakePayloadTransformer` and `fakePayloadTransformer()`;
  `registerWebhookEndpoint()` takes the delivery policy and a transform.
- Migrations: `2026_11_13_000000_add_delivery_policy_to_webhook_endpoints` and
  `2026_11_13_000001_seal_webhook_endpoint_headers`.
- `guzzlehttp/psr7` is now declared directly (it was already installed through the HTTP
  client); redirect targets are resolved with its RFC 3986 resolver.

## [0.2.1] - 2026-11-12

### Added

- `OutboundEvent` takes an optional `subject`: the host's own id for the person the
  event is about. It is stored on the event (a new nullable column with an index;
  `php artisan migrate`), never sent to the receiver.
- `Contracts\SubjectErasure` with `forgetSubject(Scope $scope, string $subject): int`
  removes that person's events in the scope with their deliveries and attempts, and
  nothing else. Events published without a subject are only removed by
  `Erasure::forget()` or by retention.

## [0.2.0] - 2026-11-12

### Breaking

- **An owner endpoint without a partition no longer receives events published with
  one.** In 0.1 an endpoint registered in `Scope::of('org_1')` also received events
  published in `Scope::of('org_1', 'eu')`, against the documented contract (the
  partition is part of the identity; null means "none", not "any"). In a host where the
  partition is the tenant, that crossed tenants. `Scope::matches()` had the same hole
  and is fixed with the query. Nothing changes for a host that uses the same shape on
  both sides, which is every known consumer. **Migration:** if you registered endpoints
  without a partition and publish with one, either publish without it or re-register
  the endpoints in the partitioned scope (`UPDATE webhook_endpoints SET partition = ?`
  for the rows concerned). Partition-wide and global endpoints are unaffected.
- **`Contracts\Deliveries` gains `paginate()`.** Callers are unaffected. A host class
  that implements or decorates `Deliveries` must add the method. **Migration:** forward
  it to the wrapped instance.

### Added

- `Contracts\RequestProfiles` with `for(Endpoint $endpoint): RequestProfile`: the user
  agent and fixed headers per endpoint (so per scope, or per anything in its metadata).
  The default reads `webhooks.http.user_agent` and the new `webhooks.http.headers`. A
  profile header can never replace a signature or transport header.
- `Contracts\SecretEncrypter` (`encrypt`, `decrypt`): the key endpoint secrets are
  stored under. The default, `Endpoints\ApplicationSecretEncrypter`, is Laravel's
  encrypter and `APP_KEY` in the format the `encrypted` cast wrote, so 0.1 rows read
  unchanged.
- `webhooks.connection`: the database connection the tables live on. The models and
  every migration follow it.
- `webhooks.telemetry.scope_attributes`: put `webhook.scope.partition` and
  `webhook.scope.key` on the publish and deliver spans. Off by default, and never a
  metric tag.
- The envelope is a strategy chosen per endpoint. `Contracts\EnvelopeFormat`
  (`single(EnvelopeItem): EncodedEnvelope`, `batch(string, list<EnvelopeItem>):
  EncodedEnvelope`) returns the final body and its content type; the deliverer signs
  and sends exactly those bytes. `Contracts\EnvelopeFormats` is the registry
  (`Webhooks::envelopes()->register($name, $format)`). Two formats ship: `cbox`, the 0.1
  envelope byte for byte and the default, and `cloudevents`, CloudEvents 1.0 structured
  JSON (and the batch format for batching endpoints), with `webhooks.cloudevents.source`
  as its `source`. A new additive migration adds `webhook_endpoints.envelope` (default
  `cbox`); `NewEndpoint`, `EndpointChanges` and `Endpoint` gain `envelope`, and the
  management API accepts it.
- Retention per scope: `Contracts\RetentionPolicies` with
  `for(Scope $scope): Retention`, and `ValueObjects\Retention(?int $deliveredDays,
  ?int $failedDays, ?int $eventsDays)` whose null fields fall back to
  `webhooks.retention`. The default binding, `Housekeeping\ConfigRetentionPolicies`,
  answers the config for every scope and the pruner then works on whole tables as in
  0.1; with a host binding it prunes scope by scope. `Testing\FakeRetentionPolicies`
  and `fakeRetentionPolicies()` come with it.
- Cursor pagination, newest first, for deliveries, attempts and events:
  `Deliveries::paginate(Scope, DeliveryFilter, ?string $cursor = null): Page`, and two
  new contracts, `Contracts\AttemptLog::paginate(Scope, AttemptFilter, ?string $cursor)`
  and `Contracts\EventLog` with `find(Scope, string $id): ?RecordedEvent` and
  `paginate(Scope, EventFilter, ?string $cursor)`. `ValueObjects\Page` carries `items`
  and an opaque `nextCursor`; `RecordedEvent`, `EventFilter` and `AttemptFilter` are
  new; `DeliveryFilter` gains `until` and `eventId`. `Webhooks::attempts()` and
  `Webhooks::events()` reach them. The management API gains `GET /deliveries`,
  `GET /attempts`, `GET /events` and `GET /events/{id}`. A cursor the package did not
  issue throws `Exceptions\InvalidCursor` (422 from the API).
- `webhook_attempts` carries its delivery's scope: a new additive migration adds
  nullable `partition` and `scope_key`, fills them from each attempt's delivery and
  indexes `(partition, scope_key, started_at)`. New attempts are written with them,
  `ValueObjects\Attempt` has a `scope`, `WebhookAttempt::scope()` exists, and
  `Erasure::forget()` deletes a scope's attempts directly. `php artisan migrate` to
  apply; see `docs/getting-started/upgrading.md`.
- `webhooks.sweep_fairness`: `none` (default, oldest first across every scope, as in
  0.1), `scope` or `partition`. With one of the latter the sweep spends its per-tick
  limit round-robin over the owners that have rows due, so one owner's backlog cannot
  delay everyone else's retries. `Enums\SweepFairness` names the values.
- `Contracts\EventCatalogs` with `for(Scope $scope): EventCatalog`: the catalog in force
  for a scope. The default, `Catalog\SharedEventCatalogs`, answers the shared
  `EventCatalog` for every scope, which is the 0.1 behaviour. A multi-tenant host binds
  its own so each customer has its own event types; publishing, subscribing, fan-out,
  replay, the management API and `webhooks:catalog --scope=` all ask it, so a type
  unknown in a scope is still refused there. `Webhooks::catalog(?Scope $scope = null)`,
  `Testing\FakeEventCatalogs` and `fakeEventCatalogs()` come with it.
- CI runs the whole suite on PostgreSQL 18 beside SQLite. Nothing in the engine had to
  change for it; two tests were added for what only a server shows: a second publisher
  waits on the endpoint row lock, and a duplicate idempotency key does not poison the
  host's transaction.

### Changed

- `database/migrations/2026_10_30_000000_create_webhook_tables.php` gained a
  `getConnection()` method so it follows `webhooks.connection`. Its schema is
  untouched and it does not run again on a host that already ran it. A host that
  published the 0.1 migrations and wants a separate connection publishes them again.
- `WebhookEndpoint` casts `secret` and `previous_secret` through
  `Models\Casts\EncryptedSecret` instead of the built-in `encrypted` cast. The stored
  format is the same with the default encrypter.
- The shipped implementations (`DatabasePublisher`, `DatabaseEndpoints`,
  `EndpointPolicy`, the catalog controller and form requests) take `EventCatalogs`
  where they took `EventCatalog`, and `EndpointPolicy::assertEventTypes()` takes the
  endpoint's scope. They are resolved from the container; nothing changes for a host
  that does not construct them by hand.

### Fixed

- The README said "Proprietary". The package is MIT, as `composer.json` and
  `LICENSE.md` say.
- An `OutboundEvent::$occurredAt` in a timezone other than the application's was stored
  as its wall-clock time, shifting the instant by the offset. It is converted before it
  is stored.

### Security

- `composer.lock` moves `symfony/http-foundation`, `symfony/http-kernel` and
  `symfony/routing` to 8.1.11 for the advisories published on 2026-11-04. The package's
  constraints are unchanged; a host resolves its own versions.

## [0.1.0] - 2026-10-30

### Added

- The outbox: `Contracts\Publisher` with `DatabasePublisher`, idempotency keys per
  scope, per-endpoint gapless sequences, after-commit unique jobs.
- The registry: `Contracts\Endpoints` with register/update/pause/resume/rotate
  secret/change scheme/delete/ping/replay/health, scoped by `ValueObjects\Scope`, wide
  endpoints only by opt-in, URL policy through `cboxdk/laravel-ssrf`.
- The delivery engine: conditional attempt claims, the retry schedule with jitter,
  `Retry-After` on 429, 410 disables, a per-endpoint circuit breaker with one probe and
  doubling cooldowns, per-minute rate limits, auto-disable after a window unreachable,
  batching by size or window, signing through `cboxdk/laravel-webhook-signature`.
- Housekeeping: the sweep (orphans, stranded rescue, due retries, batch flush,
  auto-disable, secret expiry), the pruner, `Contracts\Erasure`.
- Receiving: the `webhooks.receive` middleware, the inbound ledger, gap detection,
  `Contracts\Receivers`, queued or inline processing.
- The management API (`Http\Routes`), eight console commands, seventeen Laravel
  events, `Contracts\Monitor`, the telemetry adapters, `Testing\InteractsWithWebhooks`
  and `Testing\FakeWebhooks`.

[Unreleased]: https://github.com/cboxdk/laravel-webhooks/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/cboxdk/laravel-webhooks/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/cboxdk/laravel-webhooks/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/cboxdk/laravel-webhooks/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/cboxdk/laravel-webhooks/releases/tag/v0.1.0
