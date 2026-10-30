<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Cbox\Webhooks\Contracts\Metrics;

/** The default: the engine counts nothing until a host binds an adapter. */
final class NullMetrics implements Metrics
{
    public function counter(string $name, int $by = 1, array $tags = []): void {}

    public function histogram(string $name, float $value, array $tags = []): void {}

    public function gauge(string $name, float $value, array $tags = []): void {}
}
