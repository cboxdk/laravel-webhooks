<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\BatchFlusher;
use Cbox\Webhooks\Contracts\Sweeper;
use Cbox\Webhooks\Delivery\EndpointDisabler;
use Cbox\Webhooks\Delivery\JobDispatcher;
use Cbox\Webhooks\Endpoints\DatabaseEndpoints;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Enums\SweepFairness;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * The per-minute pass that makes "durable before queued" true.
 *
 * It runs across every scope on purpose. A scheduler process has no tenant,
 * and a sweep that filtered by an ambient scope would select nothing and
 * report success forever: no failed delivery would ever retry, no lost job
 * would ever be rescued, and orphans would pile up at the head of every
 * selection. That bug shipped once in a sibling package; this class is
 * written so it cannot be reintroduced by a host's global scope either, which
 * is why the models here are queried through the package's own classes and
 * why the tests assert a sweep with no scope set finds the rows.
 *
 * Across every scope does not have to mean first come, first served. With
 * `webhooks.sweep_fairness` set, the per-tick limit is spent round-robin over
 * the owners that have rows due, so one owner's backlog (a dead endpoint with
 * thousands of retries) cannot push everyone else's retries to a later tick.
 *
 * In order: terminalise orphans (one set-based update, so they never occupy a
 * slot), lease and re-queue due retries and stranded rows, flush due batches,
 * apply the auto-disable rule, expire previous secrets.
 */
final readonly class DatabaseSweeper implements Sweeper
{
    public function __construct(
        private Models $models,
        private Config $config,
        private JobDispatcher $jobs,
        private BatchFlusher $flusher,
        private EndpointDisabler $disabler,
        private DatabaseEndpoints $endpoints,
    ) {}

    public function sweep(): SweepReport
    {
        $now = CarbonImmutable::now();
        $limit = $this->config->positiveInt('sweep_limit', 200);
        $stranded = $now->subSeconds($this->config->positiveInt('stranded_after_seconds', 900));

        $orphans = $this->terminaliseOrphans($now);
        $rescued = $this->rescueStranded($now, $stranded, $limit);
        $retried = $this->requeueDue($now, $stranded, $limit);
        $batches = $this->flusher->flush();
        $disabled = $this->autoDisable($now);
        $expired = $this->endpoints->expirePreviousSecrets($now);

        return new SweepReport($orphans, $rescued, $retried, $batches, $disabled, $expired);
    }

    /** Deliveries whose endpoint no longer exists can never succeed: exhausted, in one update. */
    private function terminaliseOrphans(CarbonImmutable $now): int
    {
        return $this->deliveries()
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value, DeliveryStatus::InFlight->value])
            ->whereNotIn('endpoint_id', $this->endpoints()->select('id'))
            ->update([
                'status' => DeliveryStatus::Exhausted->value,
                'exhausted_at' => $now,
                'next_attempt_at' => null,
                'last_error' => 'endpoint_deleted',
                'updated_at' => $now,
            ]);
    }

    /**
     * In-flight rows older than the stranded window were claimed by a worker
     * that never settled them. They become failed-and-due with the attempt they
     * were claimed at kept, so the next claim expects that number; the HTTP
     * call may or may not have happened, which is at-least-once by definition.
     */
    private function rescueStranded(CarbonImmutable $now, CarbonImmutable $stranded, int $limit): int
    {
        $ids = $this->take(
            $this->deliveries()
                ->where('status', DeliveryStatus::InFlight->value)
                ->where('updated_at', '<=', $stranded),
            true,
            $limit,
        );

        if ($ids === []) {
            return 0;
        }

        return $this->deliveries()
            ->whereIn('id', $ids)
            ->where('status', DeliveryStatus::InFlight->value)
            ->where('updated_at', '<=', $stranded)
            ->update([
                'status' => DeliveryStatus::Failed->value,
                'next_attempt_at' => $now,
                'batch_id' => null,
                'last_error' => 'stranded',
                'updated_at' => $now,
            ]);
    }

    /**
     * Failed rows whose retry time has come, and pending rows whose job was
     * lost, of non-batching active endpoints. Each is leased before its job is
     * pushed: the conditional update moves the retry time forward by the
     * stranded window, so the next sweep leaves it alone while the job waits,
     * and only the sweep whose lease won pushes it. The deliverer overwrites
     * the lease with the attempt's real outcome.
     */
    private function requeueDue(CarbonImmutable $now, CarbonImmutable $stranded, int $limit): int
    {
        $ids = $this->take($this->due($now, $stranded), false, $limit);

        if ($ids === []) {
            return 0;
        }

        $due = $this->deliveries()->whereIn('id', $ids)->get()->keyBy('id');

        $queued = 0;
        $lease = $now->addSeconds($this->config->positiveInt('stranded_after_seconds', 900));

        foreach ($ids as $id) {
            $delivery = $due->get($id);

            if ($delivery === null) {
                continue;
            }

            $leased = $this->due($now, $stranded)
                ->whereKey($delivery->id)
                ->update(['next_attempt_at' => $lease, 'updated_at' => $now]);

            if ($leased === 1) {
                $this->jobs->delivery($delivery->id, $delivery->attempt);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function due(CarbonImmutable $now, CarbonImmutable $stranded): Builder
    {
        $activeNonBatching = $this->endpoints()
            ->select('id')
            ->where('status', EndpointStatus::Active->value)
            ->where('batch_size', '<=', 1);

        return $this->deliveries()
            ->whereIn('endpoint_id', $activeNonBatching)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $failed) => $failed
                    ->where('status', DeliveryStatus::Failed->value)
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '<=', $now))
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('status', DeliveryStatus::Pending->value)
                    ->where('updated_at', '<=', $stranded)));
    }

    /**
     * Up to `$limit` ids from the candidates, oldest first.
     *
     * Without fairness that is the whole rule. With it, every candidate is
     * ranked inside its owner by the same order, and the selection takes rank
     * 1 of every owner, then rank 2, and so on until the limit: a round-robin
     * in one statement. An owner with three rows due is fully served before
     * the owner with three thousand gets its fourth.
     *
     * @param  Builder<WebhookDelivery>  $candidates
     * @param  bool  $stranded  the in-flight rescue (ordered by last movement) rather than the due retries
     * @return list<string>
     */
    private function take(Builder $candidates, bool $stranded, int $limit): array
    {
        $order = $stranded ? ['updated_at'] : ['next_attempt_at', 'created_at'];
        $fairness = SweepFairness::tryFrom($this->config->string('sweep_fairness') ?? '') ?? SweepFairness::None;

        if ($fairness === SweepFairness::None) {
            foreach ($order as $column) {
                $candidates->orderBy($column);
            }

            return $this->ids($candidates->limit($limit)->pluck('id')->all());
        }

        $backticks = in_array($candidates->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        $ranked = $candidates
            ->select(['id', ...$order])
            ->addSelect(new Expression($this->rank($fairness, $stranded, $backticks)));

        $query = $candidates->getQuery()->newQuery()->fromSub($ranked, 'ranked')->orderBy('fair_rank');

        foreach ($order as $column) {
            $query->orderBy($column);
        }

        return $this->ids($query->limit($limit)->pluck('id')->all());
    }

    /**
     * The window that numbers each owner's candidates, oldest first. Spelled
     * out per dialect because `partition` is a keyword and has to be quoted,
     * and written as literals so no value can ever be concatenated into it.
     *
     * @return literal-string
     */
    private function rank(SweepFairness $fairness, bool $stranded, bool $backticks): string
    {
        if ($backticks) {
            return match (true) {
                $fairness === SweepFairness::Partition && $stranded => 'row_number() over (partition by `partition` order by `updated_at`) as fair_rank',
                $fairness === SweepFairness::Partition => 'row_number() over (partition by `partition` order by `next_attempt_at`, `created_at`) as fair_rank',
                $stranded => 'row_number() over (partition by `partition`, `scope_key` order by `updated_at`) as fair_rank',
                default => 'row_number() over (partition by `partition`, `scope_key` order by `next_attempt_at`, `created_at`) as fair_rank',
            };
        }

        return match (true) {
            $fairness === SweepFairness::Partition && $stranded => 'row_number() over (partition by "partition" order by "updated_at") as fair_rank',
            $fairness === SweepFairness::Partition => 'row_number() over (partition by "partition" order by "next_attempt_at", "created_at") as fair_rank',
            $stranded => 'row_number() over (partition by "partition", "scope_key" order by "updated_at") as fair_rank',
            default => 'row_number() over (partition by "partition", "scope_key" order by "next_attempt_at", "created_at") as fair_rank',
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_filter($values, is_string(...)));
    }

    private function autoDisable(CarbonImmutable $now): int
    {
        $cutoff = $now->subSeconds($this->config->positiveInt('disable_after_seconds', 259_200));
        $disabled = 0;

        $candidates = $this->endpoints()
            ->where('status', EndpointStatus::Active->value)
            ->whereNotNull('first_failure_at')
            ->where('first_failure_at', '<=', $cutoff)
            ->get();

        foreach ($candidates as $endpoint) {
            if ($this->disabler->shouldAutoDisable($endpoint, $now) && $this->disabler->disable($endpoint, DisabledReason::Unreachable, $now)) {
                $disabled++;
            }
        }

        return $disabled;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveries(): Builder
    {
        $class = $this->models->delivery();

        return $class::query();
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
