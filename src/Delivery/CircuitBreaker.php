<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;
use Illuminate\Database\Eloquent\Builder;

/**
 * A per-endpoint breaker on the endpoint's own columns, so every worker sees
 * one state without a second store.
 *
 * After `failure_threshold` consecutive failures the breaker opens and the
 * endpoint is left alone for a cooldown that doubles with each consecutive
 * trip, up to the cap. When the cooldown elapses exactly one worker is
 * admitted as the probe: admission is a conditional update on the opened-at
 * timestamp, so concurrent workers racing for the probe cannot all send. A
 * success closes the breaker and resets the trip count; a probe failure
 * re-opens it one notch longer.
 *
 * Only outcomes the receiver is responsible for feed the breaker. A refused
 * URL, a rate limit or an open breaker is this side's decision and says
 * nothing about the receiver's health.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private Config $config,
        private Models $models,
    ) {}

    public function isOpen(WebhookEndpoint $endpoint, CarbonImmutable $now): bool
    {
        $closesAt = $this->closesAt($endpoint);

        return $closesAt !== null && $closesAt->greaterThan($now);
    }

    /** When an open breaker admits its probe; null when closed. */
    public function closesAt(WebhookEndpoint $endpoint): ?CarbonImmutable
    {
        if ($endpoint->circuit_opened_at === null) {
            return null;
        }

        return $endpoint->circuit_opened_at->addSeconds($this->cooldown($endpoint->trip_count));
    }

    /**
     * Whether this worker may attempt now. Closed: yes. Open and cooling: no.
     * Open and cooled: the one worker whose conditional re-arm wins becomes the
     * probe; the others see the breaker open again for another cooldown.
     *
     * The re-arm keeps the trip count, so a probe that fails doubles from
     * where it was rather than from the base.
     */
    public function admit(WebhookEndpoint $endpoint, CarbonImmutable $now): bool
    {
        $openedAt = $endpoint->circuit_opened_at;

        if ($openedAt === null) {
            return true;
        }

        if ($this->isOpen($endpoint, $now)) {
            return false;
        }

        $won = $this->query()
            ->whereKey($endpoint->id)
            ->where('circuit_opened_at', $openedAt)
            ->update(['circuit_opened_at' => $now]);

        if ($won === 1) {
            $endpoint->circuit_opened_at = $now;

            return true;
        }

        return false;
    }

    /** Close the breaker and clear the failure run. True when it had been open (a recovery). */
    public function recordSuccess(WebhookEndpoint $endpoint, CarbonImmutable $now, ?int $responseCode): bool
    {
        $wasOpen = $endpoint->circuit_opened_at !== null;

        $this->query()->whereKey($endpoint->id)->update([
            'consecutive_failures' => 0,
            'trip_count' => 0,
            'circuit_opened_at' => null,
            'first_failure_at' => null,
            'last_success_at' => $now,
            'last_error' => null,
            'last_response_code' => $responseCode,
        ]);

        $endpoint->consecutive_failures = 0;
        $endpoint->trip_count = 0;
        $endpoint->circuit_opened_at = null;
        $endpoint->first_failure_at = null;
        $endpoint->last_success_at = $now;
        $endpoint->last_error = null;
        $endpoint->last_response_code = $responseCode;

        return $wasOpen;
    }

    /**
     * Count a failure against the endpoint. Returns when the breaker now closes
     * if this failure tripped it, null otherwise.
     *
     * The counter is bumped with an atomic increment so concurrent failures all
     * count; the trip itself is a conditional update, so only one of them fires
     * the tripped event. `$probe` is the half-open worker whose failure re-trips
     * from an already-open state.
     */
    public function recordFailure(WebhookEndpoint $endpoint, CarbonImmutable $now, ?string $error, ?int $responseCode, bool $probe): ?CarbonImmutable
    {
        $this->query()->whereKey($endpoint->id)->increment('consecutive_failures', 1, [
            'last_failure_at' => $now,
            'last_error' => $error === null ? null : mb_strcut($error, 0, 2000),
            'last_response_code' => $responseCode,
        ]);

        $this->query()->whereKey($endpoint->id)->whereNull('first_failure_at')->update(['first_failure_at' => $now]);

        $fresh = $this->query()->find($endpoint->id);

        if ($fresh === null) {
            return null;
        }

        $this->copyHealth($fresh, $endpoint);

        if ($fresh->consecutive_failures < $this->threshold()) {
            return null;
        }

        $trip = $this->query()->whereKey($endpoint->id);

        if ($probe) {
            // The probe re-armed the breaker when it was admitted; its failure
            // moves the trip count on from there.
            $trip->where('circuit_opened_at', $endpoint->circuit_opened_at);
        } else {
            $trip->whereNull('circuit_opened_at');
        }

        $tripped = $trip->update(['circuit_opened_at' => $now, 'trip_count' => $fresh->trip_count + 1]);

        if ($tripped !== 1) {
            return null;
        }

        $endpoint->circuit_opened_at = $now;
        $endpoint->trip_count = $fresh->trip_count + 1;

        return $this->closesAt($endpoint);
    }

    public function threshold(): int
    {
        return $this->config->positiveInt('breaker.failure_threshold', 5);
    }

    /** The cooldown for the n-th consecutive trip: base, doubled per trip, capped. */
    public function cooldown(int $tripCount): int
    {
        $base = $this->config->positiveInt('breaker.cooldown_seconds', 300);
        $max = $this->config->positiveInt('breaker.max_cooldown_seconds', 3600);
        $exponent = max(0, min(20, $tripCount - 1));

        return min($max, $base * (2 ** $exponent));
    }

    private function copyHealth(WebhookEndpoint $from, WebhookEndpoint $to): void
    {
        $to->consecutive_failures = $from->consecutive_failures;
        $to->trip_count = $from->trip_count;
        $to->circuit_opened_at = $from->circuit_opened_at;
        $to->first_failure_at = $from->first_failure_at;
        $to->last_failure_at = $from->last_failure_at;
        $to->last_success_at = $from->last_success_at;
        $to->last_error = $from->last_error;
        $to->last_response_code = $from->last_response_code;
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function query(): Builder
    {
        $class = $this->models->endpoint();

        return $class::query();
    }
}
