<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Cbox\Webhooks\Contracts\Erasure;
use Cbox\Webhooks\Contracts\SubjectErasure;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\Scope;

/** One scope's events, deliveries and attempts, gone. Or one person's inside it. */
final readonly class DatabaseErasure implements Erasure, SubjectErasure
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

    public function forgetSubject(Scope $scope, string $subject): int
    {
        if ($subject === '') {
            return 0;
        }

        $deliveryClass = $this->models->delivery();
        $attemptClass = $this->models->attempt();
        $eventClass = $this->models->event();

        $removed = 0;

        // Chunked by id rather than one join: the events of a person are few, and the
        // deletes stay on the primary key and the two `*_id` indexes.
        do {
            $eventIds = ScopedQuery::exact($eventClass::query(), $scope)->where('subject', $subject)->limit(500)->pluck('id')->all();

            if ($eventIds === []) {
                break;
            }

            $deliveryIds = ScopedQuery::exact($deliveryClass::query(), $scope)->whereIn('event_id', $eventIds)->pluck('id')->all();

            foreach (array_chunk($deliveryIds, 500) as $chunk) {
                $removed += $this->count($attemptClass::query()->whereIn('delivery_id', $chunk)->delete());
                $removed += $this->count($deliveryClass::query()->whereIn('id', $chunk)->delete());
            }

            $removed += $this->count($eventClass::query()->whereIn('id', $eventIds)->delete());
        } while (true);

        return $removed;
    }

    private function count(mixed $deleted): int
    {
        return is_int($deleted) ? $deleted : 0;
    }
}
