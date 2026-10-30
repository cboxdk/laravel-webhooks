---
title: Telemetry and metrics
description: What the telemetry adapter records, how to graph it, and how to plug in another metrics backend.
weight: 47
---

# Telemetry and metrics

With `cboxdk/laravel-telemetry` installed and `webhooks.telemetry.enabled` on, the
provider binds `Telemetry\TelemetryMetrics` and `Telemetry\TelemetryTracing` and
subscribes `Telemetry\TelemetryHooks`. Nothing to configure.

## Dashboards

- Throughput: `rate(webhooks_published_total)` by `type`;
  `rate(webhooks_deliveries_total)` by `outcome` and `code_class`.
- Latency: `webhooks_delivery_duration_ms` p50/p95 by `outcome`. A rising p95 on
  `success` is a slow receiver; set a rate limit or ask them to answer before working.
- Backlog: `webhooks_pending`, `webhooks_in_flight`, `webhooks_due_overdue`,
  `webhooks_oldest_pending_age_seconds`. Alert on `due_overdue > 0` for five minutes.
- Receiver health: `webhooks_endpoints{status}`, `webhooks_endpoint_trips_total`,
  `webhooks_endpoints_disabled_total{reason}`.
- Effort: `webhooks_delivery_attempts{outcome=delivered}` histogram: how many tries a
  delivery needs. Most should be 1.
- Inbound: `webhooks_inbound_total{endpoint,outcome}`, `webhooks_inbound_duration_ms`.

## Traces

`webhooks.deliver` is a client span with `server.address`, `http.response.status_code`,
`webhook.type`, `webhook.endpoint_id`, `webhook.delivery_id`, `webhook.attempt`,
`webhook.outcome`. The path is deliberately absent: endpoint URLs can carry tokens. The
span's context goes out as `traceparent`, and a receiver running this package continues
it in a `webhooks.receive` server span, so one trace crosses both apps.

## Another backend

Name a class implementing `Cbox\Webhooks\Contracts\Metrics` in `webhooks.metrics`.
It receives the engine's dotted names (`webhooks.delivery.outcome`, …) with tags; the
telemetry adapter is the reference mapping to the names above. `Monitoring\LogMetrics`
is a ready-made adapter that logs. The `Tracing` seam has no config key; bind
`Contracts\Tracing` yourself to replace it.

## Off

`webhooks.telemetry.enabled = false` binds the no-op adapters even with the package
installed.
