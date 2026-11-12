<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\EventLog;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\Http\Requests\ListEventsRequest;
use Cbox\Webhooks\ValueObjects\RecordedEvent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class EventsController
{
    use ResolvesScope;

    public function __construct(
        private EventLog $events,
        private Container $container,
    ) {}

    public function index(ListEventsRequest $request): JsonResponse
    {
        try {
            $page = $this->events->paginate($this->scope($request), $request->toFilter(), $request->cursor());
        } catch (InvalidCursor $e) {
            return $this->invalidCursor($e);
        }

        return new JsonResponse([
            'data' => array_map(static fn (RecordedEvent $e): array => $e->toArray(), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $event = $this->events->find($this->scope($request), $id);

        return $event === null ? $this->notFound() : new JsonResponse(['data' => $event->toArray()]);
    }

    protected function container(): Container
    {
        return $this->container;
    }
}
