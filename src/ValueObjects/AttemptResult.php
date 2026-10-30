<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;

/**
 * What one attempt came to and where it left the delivery. Returned by the
 * deliverer and by `Endpoints::ping()`.
 */
readonly class AttemptResult
{
    public function __construct(
        public AttemptOutcome $outcome = AttemptOutcome::Success,
        public DeliveryStatus $status = DeliveryStatus::Delivered,
        public ?int $responseCode = null,
        public int $durationMs = 0,
        public ?string $error = null,
        public ?CarbonImmutable $nextAttemptAt = null,
        public string $deliveryId = '',
        public int $attempt = 0,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome->isSuccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'delivery_id' => $this->deliveryId,
            'attempt' => $this->attempt,
            'outcome' => $this->outcome->value,
            'status' => $this->status->value,
            'response_code' => $this->responseCode,
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
            'next_attempt_at' => $this->nextAttemptAt?->toIso8601String(),
        ];
    }
}
