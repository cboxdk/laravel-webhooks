<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Telemetry;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Webhooks\Contracts\Metrics;

/**
 * The engine's metrics as laravel-telemetry instruments. The engine emits
 * dotted names through the {@see Metrics} contract; this adapter maps each to
 * its published instrument name so a dashboard built against the documented
 * names keeps working whatever the host binds. Unknown names fall back to a
 * dot-to-underscore rewrite rather than being dropped.
 */
final readonly class TelemetryMetrics implements Metrics
{
    /** @var array<string, string> engine name => instrument name */
    public const array NAMES = [
        'webhooks.event.published' => 'webhooks_published_total',
        'webhooks.delivery.outcome' => 'webhooks_deliveries_total',
        'webhooks.delivery.duration_ms' => 'webhooks_delivery_duration_ms',
        'webhooks.delivery.attempts' => 'webhooks_delivery_attempts',
        'webhooks.endpoint.tripped' => 'webhooks_endpoint_trips_total',
        'webhooks.endpoint.disabled' => 'webhooks_endpoints_disabled_total',
        'webhooks.inbound.received' => 'webhooks_inbound_total',
        'webhooks.inbound.duration_ms' => 'webhooks_inbound_duration_ms',
    ];

    /** @var list<float> */
    public const array DURATION_BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000];

    /** @var list<float> */
    public const array ATTEMPT_BUCKETS = [1, 2, 3, 4, 5, 6, 8, 10, 12];

    public function __construct(private TelemetryManager $telemetry) {}

    public function counter(string $name, int $by = 1, array $tags = []): void
    {
        $this->telemetry->counter($this->name($name))->inc((float) $by, $tags);
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $buckets = str_ends_with($name, '_ms') ? self::DURATION_BUCKETS : self::ATTEMPT_BUCKETS;

        $this->telemetry->histogram($this->name($name), $buckets)->record($value, $tags);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->telemetry->gauge($this->name($name))->set($value, $tags);
    }

    public static function name(string $engineName): string
    {
        return self::NAMES[$engineName] ?? str_replace('.', '_', $engineName);
    }
}
