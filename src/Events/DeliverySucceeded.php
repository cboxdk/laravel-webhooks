<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\Delivery;

/** The receiver answered 2xx. */
final readonly class DeliverySucceeded
{
    public function __construct(
        public Delivery $delivery,
        public Attempt $attempt,
    ) {}
}
