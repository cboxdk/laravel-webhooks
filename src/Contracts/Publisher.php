<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\PayloadTooLarge;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;

/**
 * The sender's entry point. Publishing records the event, fans it out to every
 * matching endpoint as a pending delivery, and queues the sends after the
 * surrounding transaction commits. It never makes an HTTP call itself.
 */
interface Publisher
{
    /**
     * @throws UnknownEventType when the catalog is strict and does not know the type
     * @throws PayloadTooLarge
     */
    public function publish(OutboundEvent $event): PublishedEvent;

    /**
     * Several events in one transaction: all recorded or none.
     *
     * @param  list<OutboundEvent>  $events
     * @return list<PublishedEvent>
     */
    public function publishMany(array $events): array;
}
