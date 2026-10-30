<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * What "healthy" means for `Monitor::isHealthy()`. A null bound is not checked.
 */
final readonly class Thresholds
{
    public function __construct(
        public ?int $maxDueOverdue = 0,
        public ?int $maxOldestPendingAgeSeconds = 900,
        public ?float $minSuccessRate24h = null,
        public ?int $maxInFlight = null,
    ) {}
}
