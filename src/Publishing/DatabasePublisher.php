<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Publishing;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Delivery\DeliveryMapper;
use Cbox\Webhooks\Delivery\JobDispatcher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Events\DeliveryQueued;
use Cbox\Webhooks\Events\EventPublished;
use Cbox\Webhooks\Exceptions\PayloadTooLarge;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Monitoring\ScopeAttributes;
use Cbox\Webhooks\Monitoring\SpanContext;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Records the event, fans it out and queues the sends.
 *
 * Idempotency is a unique column: the event row is inserted with a hash of
 * (partition, scope key, idempotency key), and a second publish with the same
 * key lands on the unique index and is reported as a duplicate without a new
 * fan-out. Checking first and inserting second would leave a window for two
 * relays to both pass the check.
 *
 * Fan-out allocates each endpoint's next sequence under a row lock, so two
 * publishers to the same endpoint never stamp the same number, and writes the
 * delivery row before anything is queued, so a dropped queue message still
 * leaves a row for the sweep. Jobs go out after the transaction commits.
 */
final readonly class DatabasePublisher implements Publisher
{
    public function __construct(
        private Models $models,
        private Config $config,
        private EventCatalogs $catalogs,
        private Endpoints $endpoints,
        private JobDispatcher $jobs,
        private ConnectionResolverInterface $db,
        private Dispatcher $events,
        private Metrics $metrics,
        private Tracing $tracing,
        private LoggerInterface $log,
        private ScopeAttributes $scopeAttributes,
    ) {}

    public function publish(OutboundEvent $event): PublishedEvent
    {
        return $this->publishMany([$event])[0];
    }

    public function publishMany(array $events): array
    {
        foreach ($events as $event) {
            $this->validate($event);
        }

        $connection = $this->db->connection($this->connectionName());

        return $connection->transaction(function () use ($events): array {
            $results = [];

            foreach ($events as $event) {
                $results[] = $this->record($event);
            }

            return $results;
        });
    }

    private function record(OutboundEvent $event): PublishedEvent
    {
        return $this->tracing->span('webhooks.publish', SpanKind::Internal, ['webhook.type' => $event->type, ...$this->scopeAttributes->for($event->scope)], function (SpanContext $span) use ($event): PublishedEvent {
            $stored = $this->store($event);

            if ($stored === null) {
                $span->set('webhook.duplicate', true);

                return new PublishedEvent($this->existingId($event) ?? '', 0, true);
            }

            $deliveries = 0;

            foreach ($this->endpoints->matching($event->scope, $event->type) as $endpoint) {
                $this->fanOut($stored, $endpoint);
                $deliveries++;
            }

            $published = new PublishedEvent($stored->id, $deliveries, false);

            $span->set('webhook.deliveries', $deliveries);
            $this->metrics->counter('webhooks.event.published', 1, ['type' => $event->type]);
            $this->events->dispatch(new EventPublished($event, $published));

            return $published;
        });
    }

    /** The event row, or null when the idempotency key had been seen. */
    private function store(OutboundEvent $event): ?WebhookEvent
    {
        $eventClass = $this->models->event();
        $now = CarbonImmutable::now();

        $model = new $eventClass;
        $model->fill([
            'partition' => $event->scope->partition,
            'scope_key' => $event->scope->key,
            'type' => $event->type,
            'idempotency_key' => $event->idempotencyKey,
            'dedup_hash' => $this->dedupHash($event->scope, $event->idempotencyKey),
            'payload' => $event->payload,
            // In the application's timezone: the column holds a wall-clock time, so an
            // instant given in another zone would otherwise be stored shifted.
            'occurred_at' => $event->occurredAt?->setTimezone($now->getTimezone()) ?? $now,
            'created_at' => $now,
        ]);

        if ($event->idempotencyKey === null) {
            $model->save();

            return $model;
        }

        // A savepoint, so the unique violation does not poison the surrounding
        // transaction on PostgreSQL.
        try {
            $model->getConnection()->transaction(static function () use ($model): void {
                $model->save();
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }

        return $model;
    }

    private function fanOut(WebhookEvent $event, Endpoint $endpoint): void
    {
        $deliveryClass = $this->models->delivery();
        $now = CarbonImmutable::now();

        $sequence = $this->nextSequence($endpoint->id);

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
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $delivery->save();

        $this->events->dispatch(new DeliveryQueued(DeliveryMapper::delivery($delivery)));

        // A paused endpoint keeps the row and nothing else: resume() queues the backlog in order.
        if ($endpoint->status === EndpointStatus::Paused) {
            return;
        }

        if ($endpoint->isBatching()) {
            $pending = $deliveryClass::query()
                ->where('endpoint_id', $endpoint->id)
                ->where('status', DeliveryStatus::Pending->value)
                ->count();

            $this->jobs->flush($endpoint->id, $pending >= $endpoint->batchSize ? 0 : $endpoint->batchWindowSeconds);

            return;
        }

        $this->jobs->delivery($delivery->id, 0);
    }

    /**
     * A lock-read-update on the endpoint row. A plain increment bumps the
     * database correctly but reports loaded+1 in memory, so two publishers
     * would stamp the same number; the unique (endpoint, sequence) index is
     * the backstop, this is the mechanism.
     */
    private function nextSequence(string $endpointId): int
    {
        $endpointClass = $this->models->endpoint();

        $locked = $endpointClass::query()->whereKey($endpointId)->lockForUpdate()->first();

        if (! $locked instanceof WebhookEndpoint) {
            throw new \RuntimeException(sprintf('Endpoint %s vanished during fan-out.', $endpointId));
        }

        $next = $locked->last_sequence + 1;

        $endpointClass::query()->whereKey($endpointId)->update(['last_sequence' => $next]);

        return $next;
    }

    private function validate(OutboundEvent $event): void
    {
        // The catalog of the event's own scope: a type another scope offers is unknown here.
        $catalog = $this->catalogs->for($event->scope);

        if (! $catalog->has($event->type)) {
            if ($this->config->bool('catalog.strict', true)) {
                throw UnknownEventType::forType($event->type, array_map(static fn ($d): string => $d->type, $catalog->all()));
            }

            $this->log->warning('Publishing a webhook event type the catalog does not know.', ['type' => $event->type]);
        }

        $bytes = strlen(Json::encode($event->payload));
        $limit = $this->config->positiveInt('max_payload_bytes', 262_144);

        if ($bytes > $limit) {
            throw PayloadTooLarge::bytes($bytes, $limit);
        }
    }

    private function existingId(OutboundEvent $event): ?string
    {
        $eventClass = $this->models->event();

        $id = $eventClass::query()->where('dedup_hash', $this->dedupHash($event->scope, $event->idempotencyKey))->value('id');

        return is_string($id) ? $id : null;
    }

    private function dedupHash(Scope $scope, ?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return hash('sha256', ($scope->partition ?? '').'|'.($scope->key ?? '').'|'.$idempotencyKey);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || (string) $e->getCode() === '23000'
            || (string) $e->getCode() === '23505';
    }

    private function connectionName(): ?string
    {
        $eventClass = $this->models->event();

        return (new $eventClass)->getConnectionName();
    }
}
