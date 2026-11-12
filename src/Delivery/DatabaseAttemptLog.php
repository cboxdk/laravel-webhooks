<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\AttemptLog;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Support\Keyset;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\AttemptFilter;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\Scope;

/** Attempts by their own scope columns and the (partition, scope_key, started_at) index. */
final readonly class DatabaseAttemptLog implements AttemptLog
{
    public function __construct(private Models $models) {}

    public function paginate(Scope $scope, AttemptFilter $filter, ?string $cursor = null): Page
    {
        $class = $this->models->attempt();
        $query = ScopedQuery::exact($class::query(), $scope);

        if ($filter->endpointId !== null) {
            $query->where('endpoint_id', $filter->endpointId);
        }

        if ($filter->deliveryId !== null) {
            $query->where('delivery_id', $filter->deliveryId);
        }

        if ($filter->outcome !== null) {
            $query->where('outcome', $filter->outcome->value);
        }

        if ($filter->since !== null) {
            $query->where('started_at', '>=', $filter->since);
        }

        if ($filter->until !== null) {
            $query->where('started_at', '<=', $filter->until);
        }

        return Keyset::page(
            $query,
            'started_at',
            $filter->limit,
            $cursor,
            static fn (WebhookAttempt $a): CarbonImmutable => $a->started_at,
            static fn (WebhookAttempt $a): Attempt => DeliveryMapper::attempt($a),
        );
    }
}
