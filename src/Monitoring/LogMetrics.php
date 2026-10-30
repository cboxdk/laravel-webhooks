<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Cbox\Webhooks\Contracts\Metrics;
use Psr\Log\LoggerInterface;

/** Every metric as a debug log line: for development and for proving the engine emits what the docs say. */
final readonly class LogMetrics implements Metrics
{
    public function __construct(private LoggerInterface $log) {}

    public function counter(string $name, int $by = 1, array $tags = []): void
    {
        $this->log->debug('webhooks metric', ['kind' => 'counter', 'name' => $name, 'value' => $by, 'tags' => $tags]);
    }

    public function histogram(string $name, float $value, array $tags = []): void
    {
        $this->log->debug('webhooks metric', ['kind' => 'histogram', 'name' => $name, 'value' => $value, 'tags' => $tags]);
    }

    public function gauge(string $name, float $value, array $tags = []): void
    {
        $this->log->debug('webhooks metric', ['kind' => 'gauge', 'name' => $name, 'value' => $value, 'tags' => $tags]);
    }
}
