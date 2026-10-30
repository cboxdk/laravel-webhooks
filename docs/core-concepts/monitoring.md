---
title: Monitoring
description: The Monitor contract, the overview numbers, per-endpoint stats, the health check, the sweep report, and the metrics the telemetry adapter records.
weight: 30
---

# Monitoring

## The Monitor contract

```php
$overview = Webhooks::monitor()->overview();              // every scope: for operators
$overview = Webhooks::monitor()->overview(Scope::of('org_1'));
```

`Overview`: `pending`, `inFlight`, `dueOverdue` (failed with `next_attempt_at` more
than a minute in the past: the sweep or the workers are behind), `delivered24h`,
`failed24h`, `exhausted24h`, `successRate24h`, `oldestPendingAgeSeconds`,
`endpointsActive`, `endpointsPaused`, `endpointsDisabled`, `endpointsTripped`, and,
unscoped, the inbound counts.

`endpointStats($id, Window::lastHours(24))` gives delivered, failed, exhausted counts,
the success rate, p50/p95 durations, the last success and failure. `slowestEndpoints()`
and `failingEndpoints()` rank by those. `isHealthy(new Thresholds(maxDueOverdue: 100,
maxOldestPendingAgeSeconds: 600))` answers yes or no for a health check.

## Console

- `webhooks:status [--endpoints] [--json]`
- `webhooks:endpoints [--scope=partition/key] [--status=]`
- `webhooks:deliveries [--endpoint=] [--status=] [--since=] [--limit=]`
- `webhooks:retry {delivery}`, `webhooks:replay {endpoint} --since= [--until=] [--type=]`
- `webhooks:sweep`, `webhooks:prune`, `webhooks:catalog [--markdown]`

## The sweep report

`webhooks:sweep` (and `Sweeper::sweep()`) returns `SweepReport`: `orphansTerminalised`,
`strandedRescued`, `retriesQueued`, `batchesQueued`, `endpointsDisabled`,
`secretsExpired`. A steadily non-zero `strandedRescued` means workers die mid-request
or the queue loses jobs.

## Events

Seventeen Laravel events, all `final readonly` with typed value objects:
`EventPublished`, `DeliveryQueued`, `DeliveryAttempted`, `DeliverySucceeded`,
`DeliveryFailed`, `DeliveryExhausted`, `EndpointTripped`, `EndpointRecovered`,
`EndpointDisabled`, `EndpointPaused`, `EndpointResumed`, `SecretRotated`,
`WebhookReceived`, `WebhookDuplicate`, `WebhookProcessed`, `WebhookProcessingFailed`,
`WebhookGapDetected`. Notify a customer on `EndpointDisabled`; page on
`WebhookGapDetected`.

## Metrics

Through the `Metrics` seam, recorded by the telemetry adapter when
`cboxdk/laravel-telemetry` is installed:

| Name | Type | Labels |
| --- | --- | --- |
| `webhooks_published_total` | counter | `type` |
| `webhooks_deliveries_total` | counter | `outcome`, `code_class` |
| `webhooks_delivery_duration_ms` | histogram | `outcome` |
| `webhooks_delivery_attempts` | histogram | `outcome` (attempts until delivered/exhausted) |
| `webhooks_endpoint_trips_total` | counter | |
| `webhooks_endpoints_disabled_total` | counter | `reason` |
| `webhooks_inbound_total` | counter | `endpoint`, `outcome` |
| `webhooks_inbound_duration_ms` | histogram | `endpoint` |
| `webhooks_pending`, `webhooks_in_flight`, `webhooks_due_overdue`, `webhooks_oldest_pending_age_seconds` | observable gauges | |
| `webhooks_endpoints` | observable gauge | `status` |

Spans: `webhooks.publish` (internal), `webhooks.deliver` (client, with
`server.address`, `http.response.status_code`, `webhook.outcome`, never the path) and
`webhooks.receive` (server, continuing the sender's `traceparent`). Telemetry events:
`webhooks.endpoint.tripped`, `.recovered`, `.disabled`, `webhooks.delivery.exhausted`.

Without the telemetry package, `webhooks.metrics` can name any class implementing
`Contracts\Metrics`; `Monitoring\LogMetrics` writes them to the log.
