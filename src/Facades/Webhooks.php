<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Facades;

use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Testing\FakeWebhooks;
use Cbox\Webhooks\WebhooksManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Cbox\Webhooks\ValueObjects\PublishedEvent publish(\Cbox\Webhooks\ValueObjects\OutboundEvent $event)
 * @method static list<\Cbox\Webhooks\ValueObjects\PublishedEvent> publishMany(list<\Cbox\Webhooks\ValueObjects\OutboundEvent> $events)
 * @method static \Cbox\Webhooks\Contracts\Endpoints endpoints()
 * @method static \Cbox\Webhooks\Contracts\Deliveries deliveries()
 * @method static \Cbox\Webhooks\Contracts\EnvelopeFormats envelopes()
 * @method static \Cbox\Webhooks\Contracts\AttemptLog attempts()
 * @method static \Cbox\Webhooks\Contracts\EventLog events()
 * @method static \Cbox\Webhooks\Contracts\EventCatalog catalog(?\Cbox\Webhooks\ValueObjects\Scope $scope = null)
 * @method static \Cbox\Webhooks\Contracts\Monitor monitor()
 * @method static \Cbox\Webhooks\Contracts\Receivers receivers()
 *
 * @see WebhooksManager
 */
final class Webhooks extends Facade
{
    /** Swap the publisher for the in-memory fake; endpoints, deliveries and the catalog stay real. */
    public static function fake(): FakeWebhooks
    {
        $fake = new FakeWebhooks;

        Container::getInstance()->instance(Publisher::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return WebhooksManager::class;
    }
}
