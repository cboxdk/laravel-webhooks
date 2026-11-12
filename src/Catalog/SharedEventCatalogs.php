<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Catalog;

use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Container\Container;

/**
 * The default: one catalog for every scope, the one bound to
 * {@see EventCatalog}. Resolved at call time, so a host that rebinds the
 * catalog late (or a test that swaps it) is what the engine sees.
 */
final readonly class SharedEventCatalogs implements EventCatalogs
{
    public function __construct(private Container $container) {}

    public function for(Scope $scope): EventCatalog
    {
        return $this->container->make(EventCatalog::class);
    }
}
