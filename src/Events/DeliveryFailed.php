<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\Delivery;

/** An attempt failed and a retry is scheduled (`delivery->nextAttemptAt`). */
final readonly class DeliveryFailed
{
    public function __construct(
        public Delivery $delivery,
        public Attempt $attempt,
    ) {}
}
