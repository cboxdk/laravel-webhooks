<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;

final readonly class DeliveryFilter
{
    public function __construct(
        public ?string $endpointId = null,
        public ?DeliveryStatus $status = null,
        public ?string $type = null,
        public ?CarbonImmutable $since = null,
        public int $limit = 100,
    ) {}
}
