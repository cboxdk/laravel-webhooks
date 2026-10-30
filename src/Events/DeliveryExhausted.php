<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Delivery;

/** The retry schedule ran out, the endpoint went away, or the receiver said 410: no further attempt. */
final readonly class DeliveryExhausted
{
    public function __construct(
        public Delivery $delivery,
        public string $reason,
    ) {}
}
