<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Http\Requests\StoreEndpointRequest;
use Cbox\Webhooks\Http\Requests\UpdateEndpointRequest;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EndpointsController
{
    use ResolvesScope;

    public function __construct(
        private Endpoints $endpoints,
        private Container $container,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $endpoints = $this->endpoints->list($this->scope($request));

        return new JsonResponse(['data' => array_map(static fn (Endpoint $e): array => $e->toArray(), $endpoints)]);
    }

    public function store(StoreEndpointRequest $request): JsonResponse
    {
        try {
            $registered = $this->endpoints->register($this->scope($request), $request->toNewEndpoint());
        } catch (InvalidEndpoint|UnknownEventType $e) {
            return $this->invalid($e->getMessage());
        }

        return new JsonResponse(['data' => $registered->toArray()], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $endpoint = $this->endpoints->find($this->scope($request), $id);

        return $endpoint === null ? $this->notFound() : new JsonResponse(['data' => $endpoint->toArray()]);
    }

    public function update(UpdateEndpointRequest $request, string $id): JsonResponse
    {
        try {
            $endpoint = $this->endpoints->update($this->scope($request), $id, $request->toChanges());
        } catch (InvalidEndpoint|UnknownEventType $e) {
            return $this->invalid($e->getMessage());
        }

        return $endpoint === null ? $this->notFound() : new JsonResponse(['data' => $endpoint->toArray()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->endpoints->delete($this->scope($request), $id)
            ? new JsonResponse(null, 204)
            : $this->notFound();
    }

    protected function container(): Container
    {
        return $this->container;
    }

    private function invalid(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'validation', 'errors' => ['endpoint' => [$message]]], 422);
    }
}
