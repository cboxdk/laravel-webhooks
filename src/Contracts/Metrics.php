<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

/**
 * Where the engine's counters go. `NullMetrics` by default; a host binds its own
 * adapter or lets the telemetry adapter take over when that package is present.
 * Tags never carry an endpoint id or a URL: per-endpoint detail lives in the
 * database and the Monitor, where cardinality is free.
 */
interface Metrics
{
    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function counter(string $name, int $by = 1, array $tags = []): void;

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function histogram(string $name, float $value, array $tags = []): void;

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public function gauge(string $name, float $value, array $tags = []): void;
}
