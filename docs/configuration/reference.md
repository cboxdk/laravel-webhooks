---
title: Reference
description: Every key in config/webhooks.php, its default, and what it changes.
weight: 71
---

# Configuration reference

Publish with `php artisan vendor:publish --tag=webhooks-config`.

## Storage and models

| Key | Default | Changes |
| --- | --- | --- |
| `table_prefix` | `webhook_` | every table name |
| `run_migrations` | `true` | off: publish and run them yourself |
| `models.endpoint`, `.event`, `.delivery`, `.attempt`, `.inbound` | the shipped classes | subclasses; must extend the shipped model |

## Tenancy and URLs

| Key | Default | Changes |
| --- | --- | --- |
| `allow_wide_endpoints` | `false` | lets `registerPartitionWide()` / `registerGlobal()` store endpoints |
| `allow_http` | `false` | accepts `http://` endpoint URLs |

## Catalog and payloads

| Key | Default | Changes |
| --- | --- | --- |
| `catalog.strict` | `true` | off: unknown types publish with a warning |
| `max_payload_bytes` | `262144` | `PayloadTooLarge` above it |

## Queue and HTTP

| Key | Default | Changes |
| --- | --- | --- |
| `queue.connection`, `queue.queue` | `null` | where delivery jobs run |
| `http.connect_timeout` | `5` | seconds |
| `http.timeout` | `10` | seconds |
| `http.user_agent` | `null` | replaces `Cbox-Webhooks/1.0 (+…)` |

## Retries and the breaker

| Key | Default | Changes |
| --- | --- | --- |
| `retry.schedule` | 11 entries, 10 s to 24 h | the delay before each retry; length + 1 = attempts |
| `retry.jitter` | `0.2` | ± fraction applied to each delay |
| `breaker.failure_threshold` | `5` | consecutive failures to trip |
| `breaker.cooldown_seconds` | `300` | first cooldown |
| `breaker.max_cooldown_seconds` | `3600` | cap for the doubling |
| `disable_after_seconds` | `259200` | unreachable this long since the first failure: disabled |

## Sweep and batching

| Key | Default | Changes |
| --- | --- | --- |
| `stranded_after_seconds` | `900` | pending/in-flight older than this is rescued; also the unique-job window |
| `sweep_limit` | `200` | rows re-queued per sweep |
| `sweep_fairness` | `none` | `scope` or `partition`: spend the limit round-robin over owners instead of oldest first. See [the sweep](../core-concepts/delivery-lifecycle.md#who-gets-the-tick) |
| `batch.max_size` | `100` | upper bound for an endpoint's `batch_size` |
| `batch.max_window_seconds` | `300` | upper bound for `batch_window_seconds` |

## Secrets, schedule, retention

| Key | Default | Changes |
| --- | --- | --- |
| `secret_rotation.overlap_seconds` | `86400` | default overlap for `rotateSecret()` |
| `schedule.sweep`, `schedule.prune` | `true` | register the commands on the scheduler |
| `retention.delivered_days` | `30` | delivered deliveries and attempts |
| `retention.failed_days` | `90` | exhausted and cancelled deliveries and attempts |
| `retention.events_days` | `30` | events; the replay horizon |
| `retention.inbound_days` | `30` | the inbound ledger |

The three scoped windows are the defaults; bind
[`RetentionPolicies`](../extension-points/retention.md) to answer per scope.

## Receiving

| Key | Default | Changes |
| --- | --- | --- |
| `receive.queue`, `receive.connection` | `null` | where `ProcessInboundWebhook` runs |
| `receive.inline` | `false` | run handlers in the request |
| `receive.detect_gaps` | `true` | `WebhookGapDetected` on skipped sequences |

## Observability

| Key | Default | Changes |
| --- | --- | --- |
| `metrics` | `null` | a class implementing `Contracts\Metrics`; wins over telemetry |
| `telemetry.enabled` | `true` | use the adapters when `cboxdk/laravel-telemetry` is installed |
