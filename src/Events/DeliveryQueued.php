<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Delivery;

/** A delivery row exists and its job (or batch) is on its way. */
final readonly class DeliveryQueued
{
    public function __construct(
        public Delivery $delivery,
    ) {}
}
