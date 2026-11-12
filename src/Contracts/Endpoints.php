<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\InvalidScope;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Exceptions\WideEndpointsNotAllowed;
use Cbox\Webhooks\ValueObjects\AttemptResult;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\EndpointHealth;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\RegisteredEndpoint;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Cbox\Webhooks\ValueObjects\RotatedSecret;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * The endpoint registry. Every method that takes a `Scope` filters by the exact
 * partition and key, so an id learned from another scope answers null and a
 * tenant can neither read nor silence another tenant's endpoint. There is no
 * find-by-id-alone here; the worker side has its own internals for that.
 */
interface Endpoints
{
    /**
     * An endpoint owned by one scope. The scope must have a key.
     *
     * @throws InvalidScope
     * @throws InvalidEndpoint
     * @throws UnknownEventType
     */
    public function register(Scope $scope, NewEndpoint $spec): RegisteredEndpoint;

    /**
     * An endpoint receiving every owner's events in one partition. Its own
     * method, so the tenant boundary cannot be crossed by a null that arrived
     * from a variable, and every caller of it is one grep away.
     *
     * @throws WideEndpointsNotAllowed
     */
    public function registerPartitionWide(string $partition, NewEndpoint $spec): RegisteredEndpoint;

    /**
     * @throws WideEndpointsNotAllowed
     */
    public function registerGlobal(NewEndpoint $spec): RegisteredEndpoint;

    public function update(Scope $scope, string $id, EndpointChanges $changes): ?Endpoint;

    /** Stop sending; deliveries keep accumulating as pending, in order. */
    public function pause(Scope $scope, string $id): ?Endpoint;

    /** Clear paused, disabled and the breaker, and re-queue pending deliveries in sequence order. */
    public function resume(Scope $scope, string $id): ?Endpoint;

    /**
     * Mint a new secret. The old one stays valid on the receiver's side for the
     * overlap (the receiver's secret set accepts both); this side signs with the
     * new one from the next attempt on.
     */
    public function rotateSecret(Scope $scope, string $id, ?int $overlapSeconds = null): ?RotatedSecret;

    /** Switch signature scheme; a new secret is minted in the scheme's form. */
    public function changeScheme(Scope $scope, string $id, string $scheme): ?RegisteredEndpoint;

    /** Cancels pending deliveries and removes the endpoint; history stays for retention. */
    public function delete(Scope $scope, string $id): bool;

    public function find(Scope $scope, string $id): ?Endpoint;

    /** @return list<Endpoint> */
    public function list(Scope $scope): array;

    /**
     * Endpoints whose scope covers the event's scope and whose subscriptions
     * cover the type, wildcard-aware. Active and paused ones: a paused endpoint
     * still accumulates pending deliveries so resuming replays them in order.
     * Disabled endpoints are never matched.
     *
     * @return list<Endpoint>
     */
    public function matching(Scope $eventScope, string $type): array;

    /** A synchronous `webhook.ping`, recorded as a normal delivery and attempt. */
    public function ping(Scope $scope, string $id): ?AttemptResult;

    /**
     * Re-create deliveries for stored events; returns how many. See
     * {@see ReplayRequest} for the selection (time range, event ids, types,
     * undelivered only) and the pacing.
     */
    public function replay(Scope $scope, string $id, ReplayRequest $request): int;

    /**
     * How many deliveries {@see replay()} would re-create for the same request,
     * right now, without writing anything: the number to show before a person
     * confirms a replay. Zero for an endpoint the scope does not own.
     */
    public function countReplay(Scope $scope, string $id, ReplayRequest $request): int;

    public function health(Scope $scope, string $id): ?EndpointHealth;
}
