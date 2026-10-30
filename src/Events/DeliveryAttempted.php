<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\Delivery;

/** One HTTP attempt finished, whatever the outcome. */
final readonly class DeliveryAttempted
{
    public function __construct(
        public Delivery $delivery,
        public Attempt $attempt,
    ) {}
}
