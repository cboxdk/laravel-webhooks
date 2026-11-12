<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\Http\Requests\ListDeliveriesRequest;
use Cbox\Webhooks\ValueObjects\Delivery;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DeliveriesController
{
    use ResolvesScope;

    public function __construct(
        private Deliveries $deliveries,
        private Container $container,
    ) {}

    /** Every delivery of the caller's scope, a page at a time. */
    public function index(ListDeliveriesRequest $request): JsonResponse
    {
        try {
            $page = $this->deliveries->paginate($this->scope($request), $request->toFilter(), $request->cursor());
        } catch (InvalidCursor $e) {
            return $this->invalidCursor($e);
        }

        return new JsonResponse([
            'data' => array_map(static fn (Delivery $d): array => $d->toArray(), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $delivery = $this->deliveries->find($this->scope($request), $id);

        return $delivery === null ? $this->notFound() : new JsonResponse(['data' => $delivery->toArray()]);
    }

    public function retry(Request $request, string $id): JsonResponse
    {
        $scope = $this->scope($request);

        if ($this->deliveries->find($scope, $id) === null) {
            return $this->notFound();
        }

        if (! $this->deliveries->retry($scope, $id)) {
            return new JsonResponse(['error' => 'validation', 'errors' => ['status' => ['Only failed or exhausted deliveries can be retried.']]], 422);
        }

        return new JsonResponse(['data' => ['queued' => true]], 202);
    }

    protected function container(): Container
    {
        return $this->container;
    }
}
