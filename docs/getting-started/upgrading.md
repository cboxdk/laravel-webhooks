---
title: Upgrading
description: What changes between releases, which migrations run, and the steps a host takes.
weight: 13
---

# Upgrading

Migrations are additive: a release adds new migration files and never edits a
published one. With `webhooks.run_migrations` on (the default), `php artisan migrate`
applies them. If you published the migrations, publish again
(`vendor:publish --tag=webhooks-migrations`) to get the new files.

## 0.2 to 0.3

Change the constraint to `^0.3` (or `^0.2 || ^0.3` while you roll out), then
`php artisan migrate`.

### Migrations

| Migration | Does |
| --- | --- |
| `2026_11_13_000000_add_delivery_policy_to_webhook_endpoints` | adds `retry_schedule` (json), `timeout_seconds`, `follow_redirects` (default false) and `transform` on `webhook_endpoints`; all null or false, so every endpoint delivers as before |
| `2026_11_13_000001_seal_webhook_endpoint_headers` | seals every stored header value with the bound `SecretEncrypter`, marked `sealed:`; `down()` opens them again |

The sealing migration uses the `SecretEncrypter` bound when it runs, so bind your own
before migrating if you use one. Running it twice seals nothing twice. A value without
the mark is read as plain text, so a worker still on 0.2 that writes headers during a
rolling deploy does no harm: the value is sealed the next time the row is saved, or run
the migration's `up()` again once the last old worker is gone.

### Behaviour that changed

- **Header values are masked in `Endpoint::toArray()`**, and so in the management API's
  responses (`"Bearer…"`). The `Endpoint::$headers` property still holds them opened.
- **Header rules are stricter.** At most 10 headers (the API allowed 20), values at most
  1000 characters with no CR, LF or NUL, names must be HTTP tokens, and these are
  reserved too: `Content-Encoding` and any name starting with `webhook-`, `x-webhook-`,
  `x-cbox-` or `ce-`. Existing endpoints keep sending what they have; the rules apply
  when headers are set again.
- **A 429 no longer keeps a delivery alive past its schedule.** Once the schedule is
  spent, a 429 exhausts the delivery like any other failure.
- **A delivery job's timeout is larger**: the connect timeout plus the higher of
  `http.timeout` and `http.max_endpoint_timeout` (120 by default), plus a margin. Lower
  `http.max_endpoint_timeout` if your endpoints never need two minutes, and keep your
  queue's `retry_after` above the job timeout.
- **`raw` is a registered envelope format.** A host that registered its own format
  named `raw` replaces the package's when it registers after the package's provider, as
  before.

### For code that implements or extends the package

- **`Contracts\Endpoints` gains `countReplay()`.** A class that implements or decorates
  it must add the method (a decorator forwards it).
- **`ReplayRequest::$since` is nullable** and the constructor throws
  `InvalidArgumentException` when neither `since` nor `eventIds` bounds the replay, or
  `perMinute` is below 1. Every existing call passes `since` and is unaffected.
- **`EnvelopeItem` gains `transformed`, `isTransformed` and `payload()`.** A host's own
  envelope format should put `$item->payload()` where it put `$item->data`, so the
  endpoint's transform reaches the wire.
- `Backoff::delayAfter()`, `maxDelay()` and `maxAttempts()` take an optional schedule;
  `HttpTransport::send()` takes an optional timeout and `followRedirects`;
  `JobDispatcher::delivery()` an optional delay. Existing calls are unaffected.

## 0.1 to 0.2

Composer's caret is narrow below 1.0: `^0.1` does not allow 0.2. Change the constraint
to `^0.2` (or `^0.1 || ^0.2` while you roll out).

### Behaviour that changed

- **An owner endpoint without a partition no longer receives events published with
  one.** `Scope::of('org_1')` and `Scope::of('org_1', 'eu')` are different scopes, as
  [Scopes](../core-concepts/scopes.md) always said; 0.1 delivered across them. If you
  use one shape on both sides, nothing changes. If you registered endpoints without a
  partition and publish with one, publish without it, or move the endpoints
  (`UPDATE webhook_endpoints SET partition = ? WHERE scope_key = ? AND partition IS NULL`).

- **`Contracts\Deliveries` has a new method, `paginate()`.** Calling code is not
  affected. If you implement or decorate `Deliveries` yourself, add it (a decorator
  forwards it to the inner instance).

- **An event's `occurred_at` given in another timezone is stored as the right instant.**
  0.1 stored its wall-clock time unconverted, so `created_at` in the envelope was off by
  the offset. Only publishers that pass an `occurredAt` in a zone other than the
  application's are affected, and they now get the correct time.

### Migrations

| Migration | Adds |
| --- | --- |
| `2026_11_12_000000_add_scope_to_webhook_attempts` | `partition` and `scope_key` on `webhook_attempts`, filled from each attempt's delivery, and an index on `(partition, scope_key, started_at)` |
| `2026_11_12_000001_add_envelope_to_webhook_endpoints` | `envelope` on `webhook_endpoints`, default `cbox`: every existing endpoint keeps the envelope it had |

The backfill is one `UPDATE` per column over the attempts table. On a table with many
millions of rows, run the migration off-peak, or prune first (`webhooks:prune`).

The 0.1 migration file gained a `getConnection()` method so it follows
`webhooks.connection`. Its schema is unchanged and it does not run again.

During a rolling deploy a worker still on 0.1 writes attempts without the scope
columns. They are still erased with their delivery; to make them listable by scope, run
the migration's two `UPDATE` statements once more after the last old worker is gone.
