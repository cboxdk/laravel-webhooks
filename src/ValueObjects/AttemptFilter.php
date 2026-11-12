<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\AttemptOutcome;

/**
 * Which attempts to list. `since` and `until` bound `started_at`, both
 * inclusive; `limit` is the page size (1 to 1000).
 */
final readonly class AttemptFilter
{
    public function __construct(
        public ?string $endpointId = null,
        public ?string $deliveryId = null,
        public ?AttemptOutcome $outcome = null,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
        public int $limit = 100,
    ) {}
}
