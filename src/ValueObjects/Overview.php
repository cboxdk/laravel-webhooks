<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * The numbers an operator looks at first. `dueOverdue` counts deliveries whose
 * next attempt was due more than a minute ago and nothing has picked up, which
 * is the signal that the sweep or the workers are not running.
 */
readonly class Overview
{
    public function __construct(
        public int $pending = 0,
        public int $inFlight = 0,
        public int $dueOverdue = 0,
        public int $delivered24h = 0,
        public int $failed24h = 0,
        public int $exhausted24h = 0,
        public ?float $successRate24h = null,
        public ?int $oldestPendingAgeSeconds = null,
        public int $endpointsActive = 0,
        public int $endpointsPaused = 0,
        public int $endpointsDisabled = 0,
        public int $endpointsTripped = 0,
        public int $inbound24h = 0,
        public int $inboundFailed24h = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'in_flight' => $this->inFlight,
            'due_overdue' => $this->dueOverdue,
            'delivered_24h' => $this->delivered24h,
            'failed_24h' => $this->failed24h,
            'exhausted_24h' => $this->exhausted24h,
            'success_rate_24h' => $this->successRate24h,
            'oldest_pending_age_s' => $this->oldestPendingAgeSeconds,
            'endpoints' => [
                'active' => $this->endpointsActive,
                'paused' => $this->endpointsPaused,
                'disabled' => $this->endpointsDisabled,
                'tripped' => $this->endpointsTripped,
            ],
            'inbound_24h' => $this->inbound24h,
            'inbound_failed_24h' => $this->inboundFailed24h,
        ];
    }
}
