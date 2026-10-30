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
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;
use Illuminate\Database\Eloquent\Builder;

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
        $ids = $this->deliveries()
            ->where('status', DeliveryStatus::InFlight->value)
            ->where('updated_at', '<=', $stranded)
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

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
        $due = $this->due($now, $stranded)
            ->orderBy('next_attempt_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $queued = 0;
        $lease = $now->addSeconds($this->config->positiveInt('stranded_after_seconds', 900));

        foreach ($due as $delivery) {
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
