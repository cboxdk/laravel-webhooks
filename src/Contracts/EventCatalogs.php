<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Scope;

/**
 * Which catalog is in force for a scope.
 *
 * A single-tenant application has one catalog and never touches this: the
 * default binding answers the shared {@see EventCatalog} for every scope. A
 * multi-tenant host whose customers each define their own event types binds
 * its own implementation and returns that customer's catalog.
 *
 * The engine asks here, with the scope at hand, every time it validates a
 * type: on publish (the event's scope), on registering or updating an
 * endpoint (the endpoint's scope), on fan-out and replay, and in the
 * management API (the caller's scope). So a type one customer offers is
 * unknown, and refused, in another customer's scope.
 *
 * An implementation is called often and should be cheap: cache per scope for
 * the life of the request or job. Deny by default: for a scope it does not
 * know, return an empty catalog, never another scope's.
 */
interface EventCatalogs
{
    public function for(Scope $scope): EventCatalog;
}
