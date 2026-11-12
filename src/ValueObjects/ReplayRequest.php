<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which stored events to re-create deliveries for, and how fast.
 *
 * A replay is always bounded: by `since`, by `eventIds`, or by both. With
 * `eventIds` only those events are considered, however old (`since` may be
 * left out or set to any bound); they are still held to the endpoint's scope
 * and subscriptions, so an id from another scope or of a type the endpoint
 * does not receive is skipped. `types` empty means every type the endpoint
 * subscribes to.
 *
 * `onlyUndelivered` skips an event that already has a delivery to this
 * endpoint that succeeded or is still under way (pending, in flight, or
 * failed and waiting for its next retry), so a recovery never sends twice
 * what is already arriving.
 *
 * `perMinute` paces the run: delivery `i` (from 0) is queued with a delay of
 * `floor(i * 60 / perMinute)` seconds. The endpoint's own rate limit still
 * applies on top. A batching endpoint is flushed once; its batch size and
 * window pace it, not this.
 */
final readonly class ReplayRequest
{
    /**
     * @param  list<string>  $types
     * @param  list<string>  $eventIds
     *
     * @throws InvalidArgumentException when neither `since` nor `eventIds` bounds it, or a number is out of range
     */
    public function __construct(
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
        public array $types = [],
        public int $limit = 1000,
        public array $eventIds = [],
        public bool $onlyUndelivered = false,
        public ?int $perMinute = null,
    ) {
        if ($since === null && $eventIds === []) {
            throw new InvalidArgumentException('A replay must be bounded by since or by event ids.');
        }

        if ($perMinute !== null && $perMinute < 1) {
            throw new InvalidArgumentException('perMinute must be at least 1 when set.');
        }
    }

    /**
     * These events only, whenever they occurred.
     *
     * @param  list<string>  $eventIds
     */
    public static function events(array $eventIds, bool $onlyUndelivered = false, ?int $perMinute = null): self
    {
        return new self(eventIds: $eventIds, limit: max(1, count($eventIds)), onlyUndelivered: $onlyUndelivered, perMinute: $perMinute);
    }

    /** Seconds delivery `$index` (from 0) of the run waits before it is sent. */
    public function delayFor(int $index): int
    {
        return $this->perMinute === null ? 0 : intdiv($index * 60, $this->perMinute);
    }
}
