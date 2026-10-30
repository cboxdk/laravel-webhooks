<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;

/**
 * A delivery as the API shows it. `attempts` is null when the attempts were
 * not loaded (a listing) and a list, possibly empty, when they were.
 */
readonly class Delivery
{
    /**
     * @param  list<Attempt>|null  $attempts
     */
    public function __construct(
        public string $id = '',
        public string $endpointId = '',
        public string $eventId = '',
        public Scope $scope = new Scope(null, null),
        public string $type = '',
        public int $sequence = 0,
        public DeliveryStatus $status = DeliveryStatus::Pending,
        public int $attempt = 0,
        public ?CarbonImmutable $nextAttemptAt = null,
        public ?string $batchId = null,
        public ?int $lastResponseCode = null,
        public ?string $lastError = null,
        public ?CarbonImmutable $deliveredAt = null,
        public ?CarbonImmutable $exhaustedAt = null,
        public ?int $durationMs = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public ?array $attempts = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'endpoint_id' => $this->endpointId,
            'event_id' => $this->eventId,
            'scope' => $this->scope->toArray(),
            'type' => $this->type,
            'sequence' => $this->sequence,
            'status' => $this->status->value,
            'attempt' => $this->attempt,
            'next_attempt_at' => $this->nextAttemptAt?->toIso8601String(),
            'batch_id' => $this->batchId,
            'last_response_code' => $this->lastResponseCode,
            'last_error' => $this->lastError,
            'delivered_at' => $this->deliveredAt?->toIso8601String(),
            'exhausted_at' => $this->exhaustedAt?->toIso8601String(),
            'duration_ms' => $this->durationMs,
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];

        if ($this->attempts !== null) {
            $out['attempts'] = array_map(static fn (Attempt $attempt): array => $attempt->toArray(), $this->attempts);
        }

        return $out;
    }
}
