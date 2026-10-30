<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\BatchFlusher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

/**
 * Groups a batching endpoint's due deliveries, oldest first by sequence, into
 * batches of up to `batch_size`. A group is due when it is full, when its
 * oldest member has waited the batch window, or when a failed member's retry
 * time has come.
 *
 * The grouping is the claim: one conditional update stamps the batch id, moves
 * the rows to in-flight and bumps their attempt, only on rows still claimable.
 * Two flushers running at once therefore cannot put one delivery in two
 * batches, and the one that claimed nothing queues nothing.
 */
final readonly class DatabaseBatchFlusher implements BatchFlusher
{
    public function __construct(
        private Models $models,
        private JobDispatcher $jobs,
    ) {}

    public function flush(?string $endpointId = null, bool $force = false): int
    {
        $now = CarbonImmutable::now();
        $queued = 0;

        foreach ($this->batchingEndpoints($endpointId) as $endpoint) {
            $queued += $this->flushEndpoint($endpoint, $now, $force);
        }

        return $queued;
    }

    private function flushEndpoint(WebhookEndpoint $endpoint, CarbonImmutable $now, bool $force): int
    {
        $size = max(2, $endpoint->batch_size);
        $windowStart = $now->subSeconds($endpoint->batch_window_seconds);
        $queued = 0;

        while (true) {
            $due = $this->due($endpoint->id, $now)->orderBy('sequence')->limit($size)->get()->all();

            if ($due === []) {
                return $queued;
            }

            $oldest = $due[0];
            $full = count($due) >= $size;
            $waited = $oldest->created_at !== null && $oldest->created_at->lessThanOrEqualTo($windowStart);
            $retrying = $oldest->status === DeliveryStatus::Failed;

            if (! $force && ! $full && ! $waited && ! $retrying) {
                return $queued;
            }

            $batchId = (string) Str::ulid();

            $claimed = $this->deliveries()
                ->whereIn('id', array_map(static fn (WebhookDelivery $d): string => $d->id, $due))
                ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value])
                ->update([
                    'batch_id' => $batchId,
                    'status' => DeliveryStatus::InFlight->value,
                    'attempt' => new Expression('attempt + 1'),
                    'updated_at' => $now,
                ]);

            if ($claimed === 0) {
                // Another flusher took them between the select and the claim.
                return $queued;
            }

            $this->jobs->batch($batchId);
            $queued++;

            if (! $full) {
                return $queued;
            }
        }
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function due(string $endpointId, CarbonImmutable $now): Builder
    {
        return $this->deliveries()
            ->where('endpoint_id', $endpointId)
            ->where(fn (Builder $q) => $q
                ->where('status', DeliveryStatus::Pending->value)
                ->orWhere(fn (Builder $failed) => $failed
                    ->where('status', DeliveryStatus::Failed->value)
                    ->where('next_attempt_at', '<=', $now)));
    }

    /**
     * @return list<WebhookEndpoint>
     */
    private function batchingEndpoints(?string $endpointId): array
    {
        $class = $this->models->endpoint();

        $query = $class::query()
            ->where('status', EndpointStatus::Active->value)
            ->where('batch_size', '>', 1);

        if ($endpointId !== null) {
            $query->whereKey($endpointId);
        }

        return array_values($query->get()->all());
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
