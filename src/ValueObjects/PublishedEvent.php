<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * The receipt for one publish. `duplicate` true means the idempotency key had
 * been seen in this scope: `id` is the original event's and `deliveries` is 0.
 * The zero-argument form is a valid receipt so a test double can build one.
 */
readonly class PublishedEvent
{
    public function __construct(
        public string $id = '',
        public int $deliveries = 0,
        public bool $duplicate = false,
    ) {}

    /**
     * @return array{id: string, deliveries: int, duplicate: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'deliveries' => $this->deliveries, 'duplicate' => $this->duplicate];
    }
}
