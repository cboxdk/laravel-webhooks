<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\AttemptLog;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\Http\Requests\ListAttemptsRequest;
use Cbox\Webhooks\ValueObjects\Attempt;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;

final readonly class AttemptsController
{
    use ResolvesScope;

    public function __construct(
        private AttemptLog $attempts,
        private Container $container,
    ) {}

    public function index(ListAttemptsRequest $request): JsonResponse
    {
        try {
            $page = $this->attempts->paginate($this->scope($request), $request->toFilter(), $request->cursor());
        } catch (InvalidCursor $e) {
            return $this->invalidCursor($e);
        }

        return new JsonResponse([
            'data' => array_map(static fn (Attempt $a): array => $a->toArray(), $page->items),
            'next_cursor' => $page->nextCursor,
        ]);
    }

    protected function container(): Container
    {
        return $this->container;
    }
}
