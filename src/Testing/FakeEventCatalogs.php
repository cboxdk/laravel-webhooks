<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Testing;

use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Catalog\InMemoryEventCatalog;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * A catalog per scope, in memory: what a multi-tenant host's test binds to
 * give each customer its own event types. A scope nothing was defined for
 * has an empty catalog, so every type is refused there, which is also how a
 * production implementation should behave.
 */
final class FakeEventCatalogs implements EventCatalogs
{
    /** @var array<string, InMemoryEventCatalog> keyed by `partition/key` */
    private array $catalogs = [];

    public function define(Scope $scope, EventDefinition ...$definitions): self
    {
        $this->catalog($scope)->register(...$definitions);

        return $this;
    }

    public function for(Scope $scope): EventCatalog
    {
        return $this->catalog($scope);
    }

    private function catalog(Scope $scope): InMemoryEventCatalog
    {
        return $this->catalogs[$scope->toString()] ??= new InMemoryEventCatalog;
    }
}
