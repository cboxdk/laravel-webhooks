<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Http\Requests\ListDeliveriesRequest;
use Cbox\Webhooks\Http\Requests\ReplayEndpointRequest;
use Cbox\Webhooks\ValueObjects\Delivery;
use Cbox\Webhooks\ValueObjects\Window;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EndpointActionsController
{
    use ResolvesScope;

    public function __construct(
        private Endpoints $endpoints,
        private Deliveries $deliveries,
        private Monitor $monitor,
        private Container $container,
    ) {}

    public function pause(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->endpoints->pause($this->scope($request), $id);

        return $endpoint === null ? $this->notFound() : new JsonResponse(['data' => $endpoint->toArray()]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->endpoints->resume($this->scope($request), $id);

        return $endpoint === null ? $this->notFound() : new JsonResponse(['data' => $endpoint->toArray()]);
    }

    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $overlap = $request->input('overlap_seconds');

        $rotated = $this->endpoints->rotateSecret($this->scope($request), $id, is_numeric($overlap) ? (int) $overlap : null);

        return $rotated === null ? $this->notFound() : new JsonResponse(['data' => $rotated->toArray()]);
    }

    public function ping(Request $request, string $id): JsonResponse
    {
        $result = $this->endpoints->ping($this->scope($request), $id);

        return $result === null ? $this->notFound() : new JsonResponse(['data' => $result->toArray()]);
    }

    public function replay(ReplayEndpointRequest $request, string $id): JsonResponse
    {
        $scope = $this->scope($request);

        if ($this->endpoints->find($scope, $id) === null) {
            return $this->notFound();
        }

        $count = $this->endpoints->replay($scope, $id, $request->toReplayRequest());

        return new JsonResponse(['data' => ['replayed' => $count]]);
    }

    public function deliveries(ListDeliveriesRequest $request, string $id): JsonResponse
    {
        $scope = $this->scope($request);

        if ($this->endpoints->find($scope, $id) === null) {
            return $this->notFound();
        }

        $deliveries = $this->deliveries->list($scope, $request->toFilter($id));

        return new JsonResponse(['data' => array_map(static fn (Delivery $d): array => $d->toArray(), $deliveries)]);
    }

    public function stats(Request $request, string $id): JsonResponse
    {
        $scope = $this->scope($request);

        if ($this->endpoints->find($scope, $id) === null) {
            return $this->notFound();
        }

        $hours = $request->query('hours');
        $window = Window::lastHours(is_numeric($hours) ? max(1, min(720, (int) $hours)) : 24);

        return new JsonResponse(['data' => $this->monitor->endpointStats($id, $window)->toArray()]);
    }

    protected function container(): Container
    {
        return $this->container;
    }
}
