<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

readonly class EndpointStats
{
    public function __construct(
        public string $endpointId = '',
        public int $delivered = 0,
        public int $failed = 0,
        public int $exhausted = 0,
        public int $pending = 0,
        public ?float $successRate = null,
        public ?int $p50DurationMs = null,
        public ?int $p95DurationMs = null,
        public ?CarbonImmutable $lastSuccessAt = null,
        public ?CarbonImmutable $lastFailureAt = null,
        public EndpointHealth $health = new EndpointHealth,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint_id' => $this->endpointId,
            'delivered' => $this->delivered,
            'failed' => $this->failed,
            'exhausted' => $this->exhausted,
            'pending' => $this->pending,
            'success_rate' => $this->successRate,
            'p50_duration_ms' => $this->p50DurationMs,
            'p95_duration_ms' => $this->p95DurationMs,
            'last_success_at' => $this->lastSuccessAt?->toIso8601String(),
            'last_failure_at' => $this->lastFailureAt?->toIso8601String(),
            'health' => $this->health->toArray(),
        ];
    }
}
