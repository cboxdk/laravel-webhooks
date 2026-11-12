<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Cbox\Webhooks\Contracts\Erasure;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\Scope;

/** One scope's events, deliveries and attempts, gone. */
final readonly class DatabaseErasure implements Erasure
{
    public function __construct(private Models $models) {}

    public function forget(Scope $scope): int
    {
        $deliveryClass = $this->models->delivery();
        $attemptClass = $this->models->attempt();
        $eventClass = $this->models->event();

        $deliveryIds = ScopedQuery::exact($deliveryClass::query(), $scope)->pluck('id')->all();

        $removed = 0;

        foreach (array_chunk($deliveryIds, 500) as $chunk) {
            $removed += $this->count($attemptClass::query()->whereIn('delivery_id', $chunk)->delete());
            $removed += $this->count($deliveryClass::query()->whereIn('id', $chunk)->delete());
        }

        // Attempts carry the scope themselves; the pass above catches any written
        // without it (before the columns existed, or by a worker still on the old code).
        $removed += $this->count(ScopedQuery::exact($attemptClass::query(), $scope)->delete());
        $removed += $this->count(ScopedQuery::exact($eventClass::query(), $scope)->delete());

        return $removed;
    }

    private function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }
}
