<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\Exceptions\ScopeResolverMissing;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's scope, from the host's resolver. Resolved per request rather
 * than injected, so a host that binds the resolver late (in a tenant
 * middleware) is still honoured, and so the absence of one is a clear error
 * at the first call rather than a container exception at construction.
 */
trait ResolvesScope
{
    abstract protected function container(): Container;

    protected function scope(Request $request): Scope
    {
        $container = $this->container();

        if (! $container->bound(ScopeResolver::class)) {
            throw ScopeResolverMissing::make();
        }

        return $container->make(ScopeResolver::class)->resolve($request);
    }

    protected function notFound(): JsonResponse
    {
        return new JsonResponse(['error' => 'not_found'], 404);
    }

    protected function invalidCursor(InvalidCursor $e): JsonResponse
    {
        return new JsonResponse(['error' => 'validation', 'errors' => ['cursor' => [$e->getMessage()]]], 422);
    }
}
