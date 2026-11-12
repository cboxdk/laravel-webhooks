<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Support\Config;
use Random\Randomizer;

/**
 * The retry schedule with full jitter. Attempt `n` failing waits
 * `schedule[n-1]` seconds, spread by `jitter` either way; a failure past the
 * last entry exhausts the delivery.
 *
 * The schedule is the endpoint's own when it has one (pass it as
 * `$schedule`), else the configured one; an empty schedule is one attempt and
 * no retries. The jitter is always the configured one.
 */
final readonly class Backoff
{
    private const array DEFAULT_SCHEDULE = [10, 30, 120, 600, 1800, 3600, 7200, 14400, 28800, 43200, 86400];

    public function __construct(
        private Config $config,
        private Randomizer $random = new Randomizer,
    ) {}

    /**
     * Seconds to wait after attempt `$attempt` failed, or null when the schedule is spent.
     *
     * @param  list<int>|null  $schedule  the endpoint's own; null for the configured one
     */
    public function delayAfter(int $attempt, ?array $schedule = null): ?int
    {
        $schedule = $this->schedule($schedule);
        $base = $schedule[$attempt - 1] ?? null;

        if ($base === null) {
            return null;
        }

        return $this->jittered($base);
    }

    /**
     * The largest delay the schedule allows; what a receiver's Retry-After is capped at.
     *
     * @param  list<int>|null  $schedule  the endpoint's own; null for the configured one
     */
    public function maxDelay(?array $schedule = null): int
    {
        $schedule = $this->schedule($schedule);

        return $schedule === [] ? 0 : max($schedule);
    }

    /**
     * @param  list<int>|null  $schedule  the endpoint's own; null for the configured one
     */
    public function maxAttempts(?array $schedule = null): int
    {
        return count($this->schedule($schedule)) + 1;
    }

    /**
     * Whether attempt `$attempt` was the last the schedule allows.
     *
     * @param  list<int>|null  $schedule  the endpoint's own; null for the configured one
     */
    public function spent(int $attempt, ?array $schedule = null): bool
    {
        return $attempt >= $this->maxAttempts($schedule);
    }

    public function jittered(int $seconds): int
    {
        $jitter = max(0.0, min(1.0, $this->config->float('retry.jitter', 0.2)));

        if ($jitter === 0.0 || $seconds === 0) {
            return $seconds;
        }

        $spread = (int) round($seconds * $jitter);

        return max(0, $seconds + $this->random->getInt(-$spread, $spread));
    }

    /**
     * @param  list<int>|null  $own
     * @return list<int>
     */
    private function schedule(?array $own): array
    {
        return $own ?? $this->config->intList('retry.schedule', self::DEFAULT_SCHEDULE);
    }
}
