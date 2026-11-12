---
title: Architecture
description: The modules, the contracts they expose, the five tables, and the invariants the engine keeps under concurrency.
weight: 21
---

# Architecture

Every capability is a contract in `Cbox\Webhooks\Contracts`, bound in the service
provider and resolved from the container. Depend on the interface; the implementations
are `final` and the arch test keeps them that way.

| Contract | Does | Default |
| --- | --- | --- |
| `Publisher` | stores an event, fans out deliveries, pushes jobs | `Publishing\DatabasePublisher` |
| `Endpoints` | the registry: register, update, pause, resume, rotate, ping, replay | `Endpoints\DatabaseEndpoints` |
| `Deliveries` | find, list, retry | `Delivery\DatabaseDeliveries` |
| `Deliverer` | one attempt for one delivery or one batch | `Delivery\HttpDeliverer` |
| `BatchFlusher` | groups due deliveries of batching endpoints | `Delivery\DatabaseBatchFlusher` |
| `EventCatalog` | the types you offer | `Catalog\InMemoryEventCatalog` |
| `Monitor` | overview, per-endpoint stats, health | `Monitoring\DatabaseMonitor` |
| `Sweeper`, `Pruner`, `Erasure` | housekeeping | `Housekeeping\Database*` |
| `Receivers` | inbound handlers by type pattern | `Receiving\InMemoryReceivers` |
| `ScopeResolver` | the caller's scope for the management API | none; you bind it |
| `Metrics`, `Tracing` | observability seams | telemetry adapters or no-ops |

`WebhooksManager` (behind the `Webhooks` facade) is a thin front for the ones you call
from application code: `publish`, `publishMany`, `endpoints()`, `deliveries()`,
`catalog()`, `monitor()`, `receivers()`.

## Tables

All prefixed with `webhooks.table_prefix` (`webhook_`), all keyed by ULID.

- `endpoints` — URL, scope, subscriptions, scheme, encrypted secret and previous secret,
  batch and rate settings, status, and the health columns the breaker writes
  (`consecutive_failures`, `first_failure_at`, `circuit_opened_at`, `trip_count`,
  `last_response_code`, `last_error`, `disabled_reason`).
- `events` — what was published: type, scope, payload, `occurred_at`, a `dedup_hash`
  unique per scope and idempotency key.
- `deliveries` — one per (event, endpoint): `sequence` unique per endpoint, `status`,
  `attempt`, `next_attempt_at`, `batch_id`, `last_response_code`, `last_error`.
- `attempts` — one per HTTP request, in its delivery's scope: outcome, code, duration, response excerpt,
  `request_id`, `batch_id`.
- `inbound_deliveries` — the receiver's ledger: unique per (endpoint name, delivery id).

## Invariants

- **An attempt is claimed, never assumed.** `UPDATE … SET status='in_flight',
  attempt=attempt+1 WHERE id=? AND attempt=? AND status IN ('pending','failed')`. Two
  workers with the same job send once; a job that arrives after the row moved on is a
  no-op.
- **Jobs are unique per delivery** (`ShouldBeUnique`, keyed by delivery id, for the
  stranded window) and **dispatched after commit**.
- **Settlement is one transaction**: the delivery row, the attempt row, the endpoint's
  health columns, then the Laravel events.
- **The breaker admits one probe** with a conditional update on `circuit_opened_at`.
- **Batch grouping is a conditional claim** on `batch_id IS NULL`, so two flushers do
  not form overlapping batches.
- **The sweep** runs across every scope (it is the one place that does), takes a lease
  by pushing `next_attempt_at` forward before dispatching, and terminalises orphans
  (deliveries whose endpoint is gone) in one statement so they never occupy a slot.
- **The tenant boundary is a value**, not a `WHERE` you remember to add: every query
  goes through `Support\ScopedQuery` with a `Scope`.

## Modules

```
src/
  Catalog/        event definitions, the in-memory catalog, markdown rendering
  Publishing/     DatabasePublisher
  Endpoints/      registry, policy (URL, headers, schemes, bounds), secret minting
  Delivery/       HttpDeliverer, HttpTransport, Settlement, Backoff, RetryAfter,
                  CircuitBreaker, RateLimiter, Envelope, Signer, batching, JobDispatcher
  Housekeeping/   sweeper, pruner, erasure
  Monitoring/     DatabaseMonitor, Null/Log metrics, NullTracing
  Receiving/      envelope parser, ledger, gap detector, processor, receivers
  Http/           Routes, controllers, form requests, the receive middleware
  Console/        the eight commands
  Jobs/           DeliverWebhook, DeliverBatch, FlushEndpointBatches, ProcessInboundWebhook
  Telemetry/      adapters bound only when cboxdk/laravel-telemetry is present
  Testing/        InteractsWithWebhooks, FakeWebhooks
```

Controllers are thin: validate, call a contract, map the value object to JSON. The
same goes for jobs and commands.
