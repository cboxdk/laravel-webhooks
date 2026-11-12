<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Publishing;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\EventLog;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\Support\Keyset;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\EventFilter;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\RecordedEvent;
use Cbox\Webhooks\ValueObjects\Scope;

/** The event table, read by scope on the (partition, scope_key, occurred_at) index. */
final readonly class DatabaseEventLog implements EventLog
{
    public function __construct(private Models $models) {}

    public function find(Scope $scope, string $id): ?RecordedEvent
    {
        $class = $this->models->event();
        $event = ScopedQuery::exact($class::query(), $scope)->whereKey($id)->first();

        return $event === null ? null : self::map($event);
    }

    public function paginate(Scope $scope, EventFilter $filter, ?string $cursor = null): Page
    {
        $class = $this->models->event();
        $query = ScopedQuery::exact($class::query(), $scope);

        if ($filter->type !== null) {
            $query->where('type', $filter->type);
        }

        if ($filter->since !== null) {
            $query->where('occurred_at', '>=', $filter->since);
        }

        if ($filter->until !== null) {
            $query->where('occurred_at', '<=', $filter->until);
        }

        return Keyset::page(
            $query,
            'occurred_at',
            $filter->limit,
            $cursor,
            static fn (WebhookEvent $e): CarbonImmutable => $e->occurred_at,
            self::map(...),
        );
    }

    private static function map(WebhookEvent $event): RecordedEvent
    {
        return new RecordedEvent(
            id: $event->id,
            scope: $event->scope(),
            type: $event->type,
            payload: Json::map($event->payload),
            idempotencyKey: $event->idempotency_key,
            occurredAt: $event->occurred_at,
            createdAt: $event->created_at,
        );
    }
}
