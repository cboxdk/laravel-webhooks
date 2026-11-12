<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Pruner;
use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Retention, in chunks so a large table is never locked in one statement.
 * Attempts go with their deliveries; events go after their replay horizon.
 *
 * With the default policy every scope has the same retention and each table
 * is pruned as a whole. Once a host binds its own {@see RetentionPolicies}
 * the pruner goes scope by scope: every scope that has deliveries or events
 * is asked for its retention and pruned by it, on the per-scope indexes.
 */
final readonly class DatabasePruner implements Pruner
{
    private const int CHUNK = 1000;

    public function __construct(
        private Models $models,
        private Config $config,
        private RetentionPolicies $policies,
        private ConfigRetentionPolicies $configured,
    ) {}

    public function prune(): PruneReport
    {
        $now = CarbonImmutable::now();
        $defaults = $this->configured->defaults();

        $deliveries = 0;
        $attempts = 0;
        $events = 0;

        if ($this->policies instanceof ConfigRetentionPolicies) {
            [$deliveries, $attempts, $events] = $this->pruneBy($defaults, $now, null);
        } else {
            foreach ($this->scopes() as $scope) {
                [$d, $a, $e] = $this->pruneBy($this->policies->for($scope), $now, $scope);

                $deliveries += $d;
                $attempts += $a;
                $events += $e;
            }
        }

        $inboundBefore = $now->subDays($this->config->positiveInt('retention.inbound_days', 30));
        $inboundClass = $this->models->inbound();
        $inbound = $this->chunked(fn (): int => $this->count($inboundClass::query()
            ->where('received_at', '<', $inboundBefore)
            ->limit(self::CHUNK)
            ->delete()));

        return new PruneReport($deliveries, $attempts, $events, $inbound);
    }

    /**
     * One retention applied to one scope, or to every row when `$scope` is null.
     *
     * @return array{0: int, 1: int, 2: int} deliveries, attempts, events removed
     */
    private function pruneBy(Retention $retention, CarbonImmutable $now, ?Scope $scope): array
    {
        $resolved = $retention->orElse($this->configured->defaults());

        [$deliveries, $attempts] = $this->pruneDeliveries(
            $now->subDays($resolved->deliveredDays ?? 30),
            $now->subDays($resolved->failedDays ?? 90),
            $scope,
        );

        $eventsBefore = $now->subDays($resolved->eventsDays ?? 30);

        $events = $this->chunked(fn (): int => $this->count($this->events($scope)
            ->where('occurred_at', '<', $eventsBefore)
            ->limit(self::CHUNK)
            ->delete()));

        return [$deliveries, $attempts, $events];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pruneDeliveries(CarbonImmutable $deliveredBefore, CarbonImmutable $failedBefore, ?Scope $scope): array
    {
        $deliveryClass = $this->models->delivery();
        $attemptClass = $this->models->attempt();
        $deliveries = 0;
        $attempts = 0;

        while (true) {
            $ids = $this->deliveries($scope)
                ->where(fn ($q) => $q
                    ->where(fn ($d) => $d->where('status', DeliveryStatus::Delivered->value)->where('updated_at', '<', $deliveredBefore))
                    ->orWhere(fn ($f) => $f->whereIn('status', [DeliveryStatus::Exhausted->value, DeliveryStatus::Cancelled->value])->where('updated_at', '<', $failedBefore)))
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return [$deliveries, $attempts];
            }

            $attempts += $this->count($attemptClass::query()->whereIn('delivery_id', $ids)->delete());
            $deliveries += $this->count($deliveryClass::query()->whereIn('id', $ids)->delete());
        }
    }

    /**
     * Every scope that has a delivery or an event, each once. Two short
     * `select distinct` over indexed columns; the wide shapes (no key, no
     * partition) come back as scopes too and are asked for like any other.
     *
     * @return list<Scope>
     */
    private function scopes(): array
    {
        $scopes = [];

        foreach ([$this->deliveries(null), $this->events(null)] as $query) {
            foreach ($query->toBase()->select(['partition', 'scope_key'])->distinct()->get() as $row) {
                $partition = $row->partition ?? null;
                $key = $row->scope_key ?? null;

                $scope = Scope::fromColumns(is_string($partition) ? $partition : null, is_string($key) ? $key : null);

                foreach ($scopes as $known) {
                    if ($known->equals($scope)) {
                        continue 2;
                    }
                }

                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveries(?Scope $scope): Builder
    {
        $class = $this->models->delivery();

        return $scope === null ? $class::query() : ScopedQuery::exact($class::query(), $scope);
    }

    /**
     * @return Builder<WebhookEvent>
     */
    private function events(?Scope $scope): Builder
    {
        $class = $this->models->event();

        return $scope === null ? $class::query() : ScopedQuery::exact($class::query(), $scope);
    }

    private function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param  callable(): int  $deleteChunk
     */
    private function chunked(callable $deleteChunk): int
    {
        $total = 0;

        do {
            $deleted = $deleteChunk();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
