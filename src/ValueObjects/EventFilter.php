<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Which recorded events to list. `since` and `until` bound `occurred_at`,
 * both inclusive; `limit` is the page size (1 to 1000).
 */
final readonly class EventFilter
{
    public function __construct(
        public ?string $type = null,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
        public int $limit = 100,
    ) {}
}
