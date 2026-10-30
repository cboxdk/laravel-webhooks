<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Pruner;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;

/**
 * Retention, in chunks so a large table is never locked in one statement.
 * Attempts go with their deliveries; events go after their replay horizon.
 */
final readonly class DatabasePruner implements Pruner
{
    private const int CHUNK = 1000;

    public function __construct(
        private Models $models,
        private Config $config,
    ) {}

    public function prune(): PruneReport
    {
        $now = CarbonImmutable::now();

        $deliveredBefore = $now->subDays($this->config->positiveInt('retention.delivered_days', 30));
        $failedBefore = $now->subDays($this->config->positiveInt('retention.failed_days', 90));
        $eventsBefore = $now->subDays($this->config->positiveInt('retention.events_days', 30));
        $inboundBefore = $now->subDays($this->config->positiveInt('retention.inbound_days', 30));

        [$deliveries, $attempts] = $this->pruneDeliveries($deliveredBefore, $failedBefore);

        $eventClass = $this->models->event();
        $events = $this->chunked(fn (): int => $this->count($eventClass::query()
            ->where('occurred_at', '<', $eventsBefore)
            ->limit(self::CHUNK)
            ->delete()));

        $inboundClass = $this->models->inbound();
        $inbound = $this->chunked(fn (): int => $this->count($inboundClass::query()
            ->where('received_at', '<', $inboundBefore)
            ->limit(self::CHUNK)
            ->delete()));

        return new PruneReport($deliveries, $attempts, $events, $inbound);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pruneDeliveries(CarbonImmutable $deliveredBefore, CarbonImmutable $failedBefore): array
    {
        $deliveryClass = $this->models->delivery();
        $attemptClass = $this->models->attempt();
        $deliveries = 0;
        $attempts = 0;

        while (true) {
            $ids = $deliveryClass::query()
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
