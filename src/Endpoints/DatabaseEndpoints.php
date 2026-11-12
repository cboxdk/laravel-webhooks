<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Delivery\EndpointDisabler;
use Cbox\Webhooks\Delivery\JobDispatcher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Events\EndpointPaused;
use Cbox\Webhooks\Events\EndpointResumed;
use Cbox\Webhooks\Events\SecretRotated;
use Cbox\Webhooks\Exceptions\InvalidScope;
use Cbox\Webhooks\Exceptions\WideEndpointsNotAllowed;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\AttemptResult;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\EndpointHealth;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\RegisteredEndpoint;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Cbox\Webhooks\ValueObjects\RotatedSecret;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The registry over the endpoint table. Every lookup for a caller goes through
 * {@see ScopedQuery::exact()}: a mismatch answers null, never "not yours".
 */
final readonly class DatabaseEndpoints implements Endpoints
{
    public function __construct(
        private Models $models,
        private Config $config,
        private EndpointPolicy $policy,
        private EndpointMapper $mapper,
        private EventCatalogs $catalogs,
        private JobDispatcher $jobs,
        private EndpointDisabler $disabler,
        private Deliverer $deliverer,
        private Dispatcher $events,
        private ConnectionResolverInterface $db,
    ) {}

    public function register(Scope $scope, NewEndpoint $spec): RegisteredEndpoint
    {
        if ($scope->isWide()) {
            throw InvalidScope::wideScopeNotAllowedHere('Endpoints::register()');
        }

        return $this->store($scope, $spec);
    }

    public function registerPartitionWide(string $partition, NewEndpoint $spec): RegisteredEndpoint
    {
        $this->assertWideAllowed();

        return $this->store(Scope::partitionWide($partition), $spec);
    }

    public function registerGlobal(NewEndpoint $spec): RegisteredEndpoint
    {
        $this->assertWideAllowed();

        return $this->store(Scope::global(), $spec);
    }

    public function update(Scope $scope, string $id, EndpointChanges $changes): ?Endpoint
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        $columns = [];

        if ($changes->url !== null) {
            $this->policy->assertUrl($changes->url);
            $columns['url'] = $changes->url;
        }

        if ($changes->eventTypes !== null) {
            $this->policy->assertEventTypes($changes->eventTypes, $scope);
            $columns['event_types'] = array_values(array_unique($changes->eventTypes));
        }

        if ($changes->headers !== null) {
            $this->policy->assertHeaders($changes->headers);
            $columns['headers'] = $changes->headers === [] ? null : $changes->headers;
        }

        $batchSize = $changes->batchSize ?? $endpoint->batch_size;
        $batchWindow = $changes->batchWindowSeconds ?? $endpoint->batch_window_seconds;

        if ($changes->batchSize !== null || $changes->batchWindowSeconds !== null) {
            $this->policy->assertBatch($batchSize, $batchWindow);
            $columns['batch_size'] = $batchSize;
            $columns['batch_window_seconds'] = $batchWindow;
        }

        if ($changes->clearRateLimit) {
            $columns['rate_limit_per_minute'] = null;
        } elseif ($changes->rateLimitPerMinute !== null) {
            $this->policy->assertRateLimit($changes->rateLimitPerMinute);
            $columns['rate_limit_per_minute'] = $changes->rateLimitPerMinute;
        }

        if ($changes->clearDescription) {
            $columns['description'] = null;
        } elseif ($changes->description !== null) {
            $columns['description'] = $changes->description;
        }

        if ($changes->clearApiVersion) {
            $columns['api_version'] = null;
        } elseif ($changes->apiVersion !== null) {
            $columns['api_version'] = $changes->apiVersion;
        }

        if ($changes->metadata !== null) {
            $columns['metadata'] = $changes->metadata === [] ? null : $changes->metadata;
        }

        if ($columns !== []) {
            $endpoint->forceFill($columns)->save();
        }

        return $this->mapper->endpoint($endpoint->refresh());
    }

    public function pause(Scope $scope, string $id): ?Endpoint
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        if ($endpoint->status === EndpointStatus::Active) {
            $endpoint->forceFill(['status' => EndpointStatus::Paused])->save();
            $this->events->dispatch(new EndpointPaused($this->mapper->endpoint($endpoint)));
        }

        return $this->mapper->endpoint($endpoint);
    }

    public function resume(Scope $scope, string $id): ?Endpoint
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        $endpoint->forceFill([
            'status' => EndpointStatus::Active,
            'disabled_reason' => null,
            'disabled_at' => null,
            'consecutive_failures' => 0,
            'trip_count' => 0,
            'circuit_opened_at' => null,
            'first_failure_at' => null,
        ])->save();

        $requeued = $this->requeuePending($endpoint);

        $vo = $this->mapper->endpoint($endpoint);
        $this->events->dispatch(new EndpointResumed($vo, $requeued));

        return $vo;
    }

    public function rotateSecret(Scope $scope, string $id, ?int $overlapSeconds = null): ?RotatedSecret
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $overlap = $overlapSeconds ?? $this->config->positiveInt('secret_rotation.overlap_seconds', 86_400);
        $expiresAt = $now->addSeconds(max(0, $overlap));
        $secret = SecretMinter::for($endpoint->signature_scheme);

        $endpoint->forceFill([
            'previous_secret' => $endpoint->secret,
            'previous_secret_expires_at' => $expiresAt,
            'secret' => $secret,
        ])->save();

        $vo = $this->mapper->endpoint($endpoint);
        $this->events->dispatch(new SecretRotated($vo, $expiresAt));

        return new RotatedSecret($vo, $secret, $expiresAt);
    }

    public function changeScheme(Scope $scope, string $id, string $scheme): ?RegisteredEndpoint
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        $this->policy->assertScheme($scheme);

        $secret = SecretMinter::for($scheme);

        // A scheme change is a hard cut: the old secret is in the old scheme's
        // form and would verify nothing under the new one, so there is no overlap.
        $endpoint->forceFill([
            'signature_scheme' => $scheme,
            'secret' => $secret,
            'previous_secret' => null,
            'previous_secret_expires_at' => null,
        ])->save();

        return new RegisteredEndpoint($this->mapper->endpoint($endpoint), $secret);
    }

    public function delete(Scope $scope, string $id): bool
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return false;
        }

        $this->disabler->cancelBacklog($endpoint->id, 'endpoint_deleted', CarbonImmutable::now());

        return (bool) $endpoint->delete();
    }

    public function find(Scope $scope, string $id): ?Endpoint
    {
        $endpoint = $this->owned($scope, $id);

        return $endpoint === null ? null : $this->mapper->endpoint($endpoint);
    }

    public function list(Scope $scope): array
    {
        return array_values(ScopedQuery::exact($this->endpoints(), $scope)
            ->orderBy('created_at')
            ->get()
            ->map(fn (WebhookEndpoint $e): Endpoint => $this->mapper->endpoint($e))
            ->all());
    }

    public function matching(Scope $eventScope, string $type): array
    {
        // The subscription test runs in PHP: event_types is a JSON column and a
        // portable containment predicate is not one expression. The candidate
        // set is one scope's active endpoints, not a table scan. Wildcards are
        // read against the event's scope's catalog, where the type is defined.
        $catalog = $this->catalogs->for($eventScope);

        return array_values(ScopedQuery::receiving($this->endpoints(), $eventScope)
            ->whereIn('status', [EndpointStatus::Active->value, EndpointStatus::Paused->value])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (WebhookEndpoint $e): bool => $catalog->covers(Json::stringList($e->event_types), $type))
            ->map(fn (WebhookEndpoint $e): Endpoint => $this->mapper->endpoint($e))
            ->all());
    }

    public function ping(Scope $scope, string $id): ?AttemptResult
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return null;
        }

        $delivery = $this->db->connection($endpoint->getConnectionName())->transaction(function () use ($endpoint, $scope): WebhookDelivery {
            $eventClass = $this->models->event();
            $deliveryClass = $this->models->delivery();
            $now = CarbonImmutable::now();

            $event = new $eventClass;
            $event->fill([
                'partition' => $scope->partition,
                'scope_key' => $scope->key,
                'type' => 'webhook.ping',
                'payload' => ['endpoint_id' => $endpoint->id, 'message' => 'A test delivery from Cbox Webhooks.'],
                'occurred_at' => $now,
                'created_at' => $now,
            ]);
            $event->save();

            $locked = $this->endpoints()->whereKey($endpoint->id)->lockForUpdate()->first();
            $sequence = ($locked ?? $endpoint)->last_sequence + 1;
            $this->endpoints()->whereKey($endpoint->id)->update(['last_sequence' => $sequence]);

            $delivery = new $deliveryClass;
            $delivery->fill([
                'endpoint_id' => $endpoint->id,
                'event_id' => $event->id,
                'partition' => $scope->partition,
                'scope_key' => $scope->key,
                'type' => 'webhook.ping',
                'sequence' => $sequence,
                'status' => DeliveryStatus::Pending,
                'attempt' => 0,
            ]);
            $delivery->save();

            return $delivery;
        });

        return $this->deliverer->deliver($delivery->id, 0) ?? new AttemptResult(deliveryId: $delivery->id);
    }

    public function replay(Scope $scope, string $id, ReplayRequest $request): int
    {
        $endpoint = $this->owned($scope, $id);

        if ($endpoint === null) {
            return 0;
        }

        $eventClass = $this->models->event();
        $subscriptions = Json::stringList($endpoint->event_types);
        $catalog = $this->catalogs->for($scope);

        $query = ScopedQuery::exact($eventClass::query(), $scope)
            ->where('occurred_at', '>=', $request->since)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(max(1, $request->limit));

        if ($request->until !== null) {
            $query->where('occurred_at', '<=', $request->until);
        }

        if ($request->types !== []) {
            $query->whereIn('type', $request->types);
        }

        $count = 0;

        foreach ($query->get() as $event) {
            if (! $catalog->covers($subscriptions, $event->type) && ! in_array($event->type, $request->types, true)) {
                continue;
            }

            $this->requeueEvent($endpoint, $event);
            $count++;
        }

        return $count;
    }

    public function health(Scope $scope, string $id): ?EndpointHealth
    {
        $endpoint = $this->owned($scope, $id);

        return $endpoint === null ? null : $this->mapper->health($endpoint);
    }

    /**
     * Expire previous secrets whose overlap has ended. Run by the sweep.
     */
    public function expirePreviousSecrets(CarbonImmutable $now): int
    {
        return $this->endpoints()
            ->whereNotNull('previous_secret')
            ->where('previous_secret_expires_at', '<=', $now)
            ->update(['previous_secret' => null, 'previous_secret_expires_at' => null]);
    }

    private function store(Scope $scope, NewEndpoint $spec): RegisteredEndpoint
    {
        $this->policy->assertUrl($spec->url);
        $this->policy->assertEventTypes($spec->eventTypes, $scope);
        $this->policy->assertScheme($spec->scheme);
        $this->policy->assertHeaders($spec->headers);
        $this->policy->assertBatch($spec->batchSize, $spec->batchWindowSeconds);
        $this->policy->assertRateLimit($spec->rateLimitPerMinute);

        $endpointClass = $this->models->endpoint();
        $secret = SecretMinter::for($spec->scheme);

        $endpoint = new $endpointClass;
        $endpoint->fill([
            'partition' => $scope->partition,
            'scope_key' => $scope->key,
            'url' => $spec->url,
            'description' => $spec->description,
            'event_types' => array_values(array_unique($spec->eventTypes)),
            'signature_scheme' => $spec->scheme,
            'secret' => $secret,
            'status' => EndpointStatus::Active,
            'batch_size' => $spec->batchSize,
            'batch_window_seconds' => $spec->batchWindowSeconds,
            'rate_limit_per_minute' => $spec->rateLimitPerMinute,
            'headers' => $spec->headers === [] ? null : $spec->headers,
            'api_version' => $spec->apiVersion,
            'metadata' => $spec->metadata === [] ? null : $spec->metadata,
        ]);
        $endpoint->save();

        return new RegisteredEndpoint($this->mapper->endpoint($endpoint), $secret);
    }

    /** Pending deliveries back onto the queue in sequence order; batching endpoints are left to the flusher. */
    private function requeuePending(WebhookEndpoint $endpoint): int
    {
        $deliveryClass = $this->models->delivery();

        $pending = $deliveryClass::query()
            ->where('endpoint_id', $endpoint->id)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value])
            ->orderBy('sequence')
            ->get();

        if ($endpoint->isBatching()) {
            if ($pending->isNotEmpty()) {
                $this->jobs->flush($endpoint->id, 0);
            }

            return $pending->count();
        }

        foreach ($pending as $delivery) {
            $deliveryClass::query()->whereKey($delivery->id)->update(['next_attempt_at' => null]);
            $this->jobs->delivery($delivery->id, $delivery->attempt);
        }

        return $pending->count();
    }

    private function requeueEvent(WebhookEndpoint $endpoint, WebhookEvent $event): void
    {
        $deliveryClass = $this->models->delivery();

        $this->db->connection($endpoint->getConnectionName())->transaction(function () use ($endpoint, $event, $deliveryClass): void {
            $locked = $this->endpoints()->whereKey($endpoint->id)->lockForUpdate()->first();
            $sequence = ($locked ?? $endpoint)->last_sequence + 1;
            $this->endpoints()->whereKey($endpoint->id)->update(['last_sequence' => $sequence]);
            $endpoint->last_sequence = $sequence;

            $delivery = new $deliveryClass;
            $delivery->fill([
                'endpoint_id' => $endpoint->id,
                'event_id' => $event->id,
                'partition' => $event->partition,
                'scope_key' => $event->scope_key,
                'type' => $event->type,
                'sequence' => $sequence,
                'status' => DeliveryStatus::Pending,
                'attempt' => 0,
            ]);
            $delivery->save();

            if ($endpoint->isBatching()) {
                $this->jobs->flush($endpoint->id, $endpoint->batch_window_seconds);
            } else {
                $this->jobs->delivery($delivery->id, 0);
            }
        });
    }

    private function owned(Scope $scope, string $id): ?WebhookEndpoint
    {
        return ScopedQuery::exact($this->endpoints(), $scope)->whereKey($id)->first();
    }

    private function assertWideAllowed(): void
    {
        if (! $this->config->bool('allow_wide_endpoints', false)) {
            throw WideEndpointsNotAllowed::make();
        }
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function endpoints(): Builder
    {
        $class = $this->models->endpoint();

        return $class::query();
    }
}
