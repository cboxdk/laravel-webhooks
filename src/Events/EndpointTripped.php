<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\Endpoint;

/** The breaker opened after consecutive failures; deliveries wait until `closesAt`. */
final readonly class EndpointTripped
{
    public function __construct(
        public Endpoint $endpoint,
        public CarbonImmutable $closesAt,
    ) {}
}
