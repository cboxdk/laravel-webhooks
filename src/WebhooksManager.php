<?php

declare(strict_types=1);

namespace Cbox\Webhooks;

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Container\Container;

/**
 * The front door behind the facade. Every method resolves its contract at
 * call time rather than at construction, so a fake bound mid-test (or a host
 * decorating a contract late) is what the facade reaches.
 */
final readonly class WebhooksManager
{
    public function __construct(private Container $container) {}

    public function publish(OutboundEvent $event): PublishedEvent
    {
        return $this->publisher()->publish($event);
    }

    /**
     * @param  list<OutboundEvent>  $events
     * @return list<PublishedEvent>
     */
    public function publishMany(array $events): array
    {
        return $this->publisher()->publishMany($events);
    }

    public function endpoints(): Endpoints
    {
        return $this->container->make(Endpoints::class);
    }

    public function deliveries(): Deliveries
    {
        return $this->container->make(Deliveries::class);
    }

    /**
     * The shared catalog a provider registers into, or, given a scope, the
     * catalog in force for it (the same one unless the host bound its own
     * {@see EventCatalogs}).
     */
    public function catalog(?Scope $scope = null): EventCatalog
    {
        if ($scope === null) {
            return $this->container->make(EventCatalog::class);
        }

        return $this->container->make(EventCatalogs::class)->for($scope);
    }

    public function monitor(): Monitor
    {
        return $this->container->make(Monitor::class);
    }

    public function receivers(): Receivers
    {
        return $this->container->make(Receivers::class);
    }

    private function publisher(): Publisher
    {
        return $this->container->make(Publisher::class);
    }
}
