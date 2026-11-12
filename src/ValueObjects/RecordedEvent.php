<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * An event as the outbox holds it: what was published, in which scope, and
 * when. The replay source, until retention removes it. The zero-argument form
 * is a valid, empty event so a test double can return one.
 */
readonly class RecordedEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id = '',
        public Scope $scope = new Scope(null, null),
        public string $type = '',
        public array $payload = [],
        public ?string $idempotencyKey = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->toArray(),
            'type' => $this->type,
            'payload' => $this->payload,
            'idempotency_key' => $this->idempotencyKey,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }
}
