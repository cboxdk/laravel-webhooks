<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The breaker and failure state of one endpoint as a snapshot a console can
 * render without knowing the columns or the breaker's config.
 */
readonly class EndpointHealth
{
    public function __construct(
        public int $consecutiveFailures = 0,
        public int $tripCount = 0,
        public ?CarbonImmutable $circuitOpenedAt = null,
        public ?CarbonImmutable $circuitClosesAt = null,
        public ?CarbonImmutable $firstFailureAt = null,
        public ?CarbonImmutable $lastFailureAt = null,
        public ?CarbonImmutable $lastSuccessAt = null,
        public ?string $lastError = null,
        public ?int $lastResponseCode = null,
    ) {}

    /** True while the breaker is open and its cooldown has not elapsed. */
    public function isTripped(): bool
    {
        return $this->circuitClosesAt !== null && $this->circuitClosesAt->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tripped' => $this->isTripped(),
            'consecutive_failures' => $this->consecutiveFailures,
            'trip_count' => $this->tripCount,
            'circuit_opened_at' => $this->circuitOpenedAt?->toIso8601String(),
            'circuit_closes_at' => $this->circuitClosesAt?->toIso8601String(),
            'first_failure_at' => $this->firstFailureAt?->toIso8601String(),
            'last_failure_at' => $this->lastFailureAt?->toIso8601String(),
            'last_success_at' => $this->lastSuccessAt?->toIso8601String(),
            'last_error' => $this->lastError,
            'last_response_code' => $this->lastResponseCode,
        ];
    }
}
