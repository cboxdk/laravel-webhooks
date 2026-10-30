<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * A per-endpoint per-minute bucket in the cache. The window is the calendar
 * minute, counted with the store's atomic increment, so every worker sees one
 * number; a request over the limit waits for the next window plus one slot.
 *
 * Correct only against a cache shared by every worker, like any rate limiter.
 */
final readonly class RateLimiter
{
    public function __construct(private Repository $cache) {}

    /** Take one slot; false when the minute is full. */
    public function acquire(string $endpointId, int $limitPerMinute, CarbonImmutable $now): bool
    {
        $key = $this->key($endpointId, $now);

        $this->cache->add($key, 0, 120);

        $count = $this->cache->increment($key);

        return is_int($count) ? $count <= $limitPerMinute : true;
    }

    /** When to try again after a refusal. */
    public function retryAt(int $limitPerMinute, CarbonImmutable $now): CarbonImmutable
    {
        $slot = (int) ceil(60 / max(1, $limitPerMinute));

        return $now->addSeconds(max(1, $slot));
    }

    private function key(string $endpointId, CarbonImmutable $now): string
    {
        return 'webhooks:rate:'.$endpointId.':'.$now->format('YmdHi');
    }
}
