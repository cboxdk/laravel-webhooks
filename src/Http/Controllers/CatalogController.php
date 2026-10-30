<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Contracts\EventCatalog;
use Illuminate\Http\JsonResponse;

final readonly class CatalogController
{
    public function __construct(private EventCatalog $catalog) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(static fn (EventDefinition $d): array => $d->toArray(), $this->catalog->all()),
        ]);
    }
}
