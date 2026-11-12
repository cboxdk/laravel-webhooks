# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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

- The shipped implementations (`DatabasePublisher`, `DatabaseEndpoints`,
  `EndpointPolicy`, the catalog controller and form requests) take `EventCatalogs`
  where they took `EventCatalog`, and `EndpointPolicy::assertEventTypes()` takes the
  endpoint's scope. They are resolved from the container; nothing changes for a host
  that does not construct them by hand.

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

[Unreleased]: https://github.com/cboxdk/laravel-webhooks/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/cboxdk/laravel-webhooks/releases/tag/v0.1.0
