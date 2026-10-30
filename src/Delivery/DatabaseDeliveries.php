<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\Delivery;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Builder;

final readonly class DatabaseDeliveries implements Deliveries
{
    public function __construct(
        private Models $models,
        private JobDispatcher $jobs,
    ) {}

    public function find(Scope $scope, string $id): ?Delivery
    {
        $delivery = ScopedQuery::exact($this->deliveries(), $scope)->whereKey($id)->first();

        if ($delivery === null) {
            return null;
        }

        $attemptClass = $this->models->attempt();

        $attempts = $attemptClass::query()
            ->where('delivery_id', $delivery->id)
            ->orderBy('attempt_number')
            ->orderBy('started_at')
            ->get()
            ->map(static fn (WebhookAttempt $a) => DeliveryMapper::attempt($a))
            ->all();

        return DeliveryMapper::delivery($delivery, array_values($attempts));
    }

    public function list(Scope $scope, DeliveryFilter $filter): array
    {
        $query = ScopedQuery::exact($this->deliveries(), $scope);

        if ($filter->endpointId !== null) {
            $query->where('endpoint_id', $filter->endpointId);
        }

        if ($filter->status !== null) {
            $query->where('status', $filter->status->value);
        }

        if ($filter->type !== null) {
            $query->where('type', $filter->type);
        }

        if ($filter->since !== null) {
            $query->where('created_at', '>=', $filter->since);
        }

        return array_values($query
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->limit(max(1, min(1000, $filter->limit)))
            ->get()
            ->map(static fn (WebhookDelivery $d) => DeliveryMapper::delivery($d))
            ->all());
    }

    /**
     * Only failed and exhausted deliveries can be retried by hand: a pending
     * one is already on its way, an in-flight one is being sent, a delivered
     * one is done, and a cancelled one was cancelled because its endpoint is
     * gone or disabled. The row is moved to failed-and-due so the claim's
     * expected attempt is whatever it is now.
     */
    public function retry(Scope $scope, string $id): bool
    {
        $delivery = ScopedQuery::exact($this->deliveries(), $scope)->whereKey($id)->first();

        if ($delivery === null || ! in_array($delivery->status, [DeliveryStatus::Failed, DeliveryStatus::Exhausted], true)) {
            return false;
        }

        $now = CarbonImmutable::now();

        $this->deliveries()->whereKey($delivery->id)->update([
            'status' => DeliveryStatus::Failed->value,
            'next_attempt_at' => $now,
            'exhausted_at' => null,
            'updated_at' => $now,
        ]);

        $endpointClass = $this->models->endpoint();
        $endpoint = $endpointClass::query()->find($delivery->endpoint_id);

        if ($endpoint !== null && $endpoint->isBatching()) {
            $this->jobs->flush($endpoint->id, 0);
        } else {
            $this->jobs->delivery($delivery->id, $delivery->attempt);
        }

        return true;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveries(): Builder
    {
        $class = $this->models->delivery();

        return $class::query();
    }
}
