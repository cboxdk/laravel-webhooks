---
title: Installation
description: Composer, migrations, the config file, queue workers and the scheduled sweep.
weight: 11
---

# Installation

```bash
composer require cboxdk/laravel-webhooks
php artisan migrate
```

## Migrations

The package loads its migrations itself (`webhooks.run_migrations`). To own them, turn
that off and publish:

```bash
php artisan vendor:publish --tag=webhooks-migrations
```

Migrations are additive. A shipped migration's schema is never edited after release;
schema changes arrive as new files.

### A database of its own

Set `webhooks.connection` to the name of a connection in `config/database.php` and the
five tables live there: the models use it, and the package's migrations run on it
whichever connection `migrate` was started for. The transactions the engine opens
(publish, replay, ping) are on that connection, so a publish no longer joins a
transaction your application opened on its default connection: publish after your own
commit, or keep both on one connection if you rely on the outbox joining your
transaction.

If you published the migrations under 0.1, publish them again
(`vendor:publish --tag=webhooks-migrations --force`) before setting a connection: the
0.1 copy does not know the key.

## Configuration

```bash
php artisan vendor:publish --tag=webhooks-config
```

Every key is explained in the [configuration reference](../configuration/reference.md).
The defaults are production values: a twelve-attempt schedule over two days, a breaker
that trips after five consecutive failures, auto-disable after three days unreachable.

## Queue and scheduler

Deliveries run as queue jobs (`DeliverWebhook`, `DeliverBatch`, `FlushEndpointBatches`).
Point them at a connection and queue of their own with `webhooks.queue`; a webhook
storm should not starve your mail.

Two commands are scheduled when `webhooks.schedule.*` is on (the default):

- `webhooks:sweep` every minute: terminalises orphans, rescues stranded deliveries,
  re-queues due retries, flushes batches whose window elapsed, auto-disables endpoints,
  expires rotated secrets.
- `webhooks:prune` daily: removes rows past retention.

Both run `withoutOverlapping`. Turn a flag off to schedule the command yourself.

## Signing

Signing goes through `cboxdk/laravel-webhook-signature`, which the package requires.
Two schemes are available for outbound endpoints out of the box: `cbox`
(`X-Cbox-Signature: t=…,v1=…`) and `standard-webhooks` (`webhook-id`,
`webhook-timestamp`, `webhook-signature`). See [Signing](../security/signing.md).

## Telemetry

If `cboxdk/laravel-telemetry` is installed and `webhooks.telemetry.enabled` is on, the
engine records counters, histograms, observable gauges and spans, and sends a
`traceparent` header with every delivery. Nothing else changes.
