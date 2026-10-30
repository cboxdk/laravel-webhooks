<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;

/** An event was recorded and fanned out. `deliveries` is how many endpoints it reached. */
final readonly class EventPublished
{
    public function __construct(
        public OutboundEvent $event,
        public PublishedEvent $published,
    ) {}
}
