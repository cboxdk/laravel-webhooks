---
title: Metrics
description: Implement Contracts\Metrics for another backend, and what the engine records through it.
weight: 54
---

# Metrics

```php
final readonly class StatsdMetrics implements \Cbox\Webhooks\Contracts\Metrics
{
    public function counter(string $name, int $by = 1, array $tags = []): void { … }
    public function histogram(string $name, float $value, array $tags = []): void { … }
    public function gauge(string $name, float $value, array $tags = []): void { … }
}
```

```php
'metrics' => App\Observability\StatsdMetrics::class,
```

A named class wins over the telemetry adapter. The engine records, with dotted names:

| Name | Kind | Tags |
| --- | --- | --- |
| `webhooks.event.published` | counter | `type` |
| `webhooks.delivery.outcome` | counter | `outcome`, `code_class` |
| `webhooks.delivery.duration_ms` | histogram | `outcome` |
| `webhooks.delivery.attempts` | histogram | `outcome` |
| `webhooks.endpoint.tripped` | counter | |
| `webhooks.endpoint.disabled` | counter | `reason` |
| `webhooks.inbound.received` | counter | `endpoint`, `outcome` |
| `webhooks.inbound.duration_ms` | histogram | `endpoint` |

`Telemetry\TelemetryMetrics::NAMES` is the mapping to the Prometheus-style names the
telemetry adapter exposes; mirror it if you want the same names elsewhere. The
observable gauges (`pending`, `in_flight`, …) are not pushed through this seam: read
`Monitor::overview()` from your own collector.

`Monitoring\LogMetrics` is a working adapter that writes to the log at `debug`.
