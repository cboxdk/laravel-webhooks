<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;

/**
 * Which deliveries to list. `since` and `until` bound `created_at`, both
 * inclusive; `limit` is the number of rows per call (1 to 1000), which for
 * `Deliveries::paginate()` is the page size.
 */
final readonly class DeliveryFilter
{
    public function __construct(
        public ?string $endpointId = null,
        public ?DeliveryStatus $status = null,
        public ?string $type = null,
        public ?CarbonImmutable $since = null,
        public int $limit = 100,
        public ?CarbonImmutable $until = null,
        public ?string $eventId = null,
    ) {}
}
