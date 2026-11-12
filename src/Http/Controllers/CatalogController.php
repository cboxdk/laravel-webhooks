<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CatalogController
{
    public function __construct(
        private EventCatalog $catalog,
        private EventCatalogs $catalogs,
        private Container $container,
    ) {}

    /**
     * The caller's catalog. With a scope resolver bound that is the catalog of
     * the caller's scope; without one (a host that only exposes the catalog)
     * it is the shared catalog, as before.
     */
    public function index(Request $request): JsonResponse
    {
        $catalog = $this->container->bound(ScopeResolver::class)
            ? $this->catalogs->for($this->container->make(ScopeResolver::class)->resolve($request))
            : $this->catalog;

        return new JsonResponse([
            'data' => array_map(static fn (EventDefinition $d): array => $d->toArray(), $catalog->all()),
        ]);
    }
}
