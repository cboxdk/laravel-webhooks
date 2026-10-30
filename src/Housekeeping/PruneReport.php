<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

final readonly class PruneReport
{
    public function __construct(
        public int $deliveries = 0,
        public int $attempts = 0,
        public int $events = 0,
        public int $inbound = 0,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return ['deliveries' => $this->deliveries, 'attempts' => $this->attempts, 'events' => $this->events, 'inbound' => $this->inbound];
    }
}
