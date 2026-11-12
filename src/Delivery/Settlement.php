<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Endpoints\EndpointMapper;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\Events\DeliveryAttempted;
use Cbox\Webhooks\Events\DeliveryExhausted;
use Cbox\Webhooks\Events\DeliveryFailed;
use Cbox\Webhooks\Events\DeliverySucceeded;
use Cbox\Webhooks\Events\EndpointRecovered;
use Cbox\Webhooks\Events\EndpointTripped;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\AttemptResult;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Turns a transport result into state: delivery rows, attempt rows, the
 * endpoint's breaker, the Laravel events and the metrics. One place, so a
 * single delivery and a batch settle by exactly the same rules.
 *
 * The rules, by response:
 *  - 2xx: delivered; the breaker closes.
 *  - 410: exhausted at once and the endpoint disabled (reason `gone`). The
 *    receiver asked us to stop; the standard thing is to listen.
 *  - 429: a retry after `Retry-After` (capped at the schedule's maximum) or the
 *    backoff, counted as rate-limited, not against the breaker; exhausted once
 *    the schedule is spent, like any other failure.
 *  - Any other 4xx, 5xx, a timeout or a connection error: a retry on the
 *    schedule and a breaker failure. A 404 may be a deploy gap, so it is not
 *    treated as final.
 *  - A refused URL: a retry on the schedule, no breaker failure; it is this
 *    side's policy, not the receiver's health.
 */
final readonly class Settlement
{
    public function __construct(
        private Models $models,
        private Backoff $backoff,
        private CircuitBreaker $breaker,
        private EndpointDisabler $disabler,
        private EndpointMapper $endpoints,
        private Dispatcher $events,
        private Metrics $metrics,
    ) {}

    /**
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function settle(array $deliveries, WebhookEndpoint $endpoint, TransportResult $result, ?string $batchId, bool $probing): AttemptResult
    {
        $now = CarbonImmutable::now();
        $outcome = $result->outcome;
        $code = $result->responseCode;

        if ($code === 429) {
            $outcome = AttemptOutcome::RateLimited;
        }

        $attempts = $this->recordAttempts($deliveries, $endpoint, $result, $outcome, $batchId, $now);

        $this->metrics->counter('webhooks.delivery.outcome', count($deliveries), [
            'outcome' => $outcome->value,
            'code_class' => $this->codeClass($code),
        ]);
        $this->metrics->histogram('webhooks.delivery.duration_ms', (float) $result->durationMs, ['outcome' => $outcome->value]);

        if ($outcome->isSuccess()) {
            return $this->succeed($deliveries, $endpoint, $result, $attempts, $now);
        }

        if ($code === 410) {
            $this->disabler->disable($endpoint, DisabledReason::Gone, $now);

            $this->announceAttempts($deliveries, $attempts);

            return $this->exhaust($deliveries, $endpoint, 'gone', $result);
        }

        $maxAttempt = $this->highestAttempt($deliveries);

        $schedule = $endpoint->retrySchedule();

        $delay = $outcome === AttemptOutcome::RateLimited
            ? $this->rateLimitDelay($result, $maxAttempt, $schedule, $now)
            : $this->backoff->delayAfter($maxAttempt, $schedule);

        if ($outcome->countsAgainstEndpoint()) {
            $closesAt = $this->breaker->recordFailure($endpoint, $now, $result->error, $code, $probing);

            if ($closesAt !== null) {
                $this->metrics->counter('webhooks.endpoint.tripped');
                $this->events->dispatch(new EndpointTripped($this->endpoints->endpoint($endpoint), $closesAt));
            }

            if ($this->disabler->shouldAutoDisable($endpoint, $now)) {
                $this->disabler->disable($endpoint, DisabledReason::Unreachable, $now);

                $this->announceAttempts($deliveries, $attempts);

                return $this->cancel($deliveries, $endpoint, 'endpoint_disabled', $result, $maxAttempt);
            }
        }

        $this->announceAttempts($deliveries, $attempts);

        if ($delay === null) {
            return $this->exhaust($deliveries, $endpoint, 'retries_exhausted', $result);
        }

        $nextAttemptAt = $now->addSeconds($delay);

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Failed->value,
                'next_attempt_at' => $nextAttemptAt,
                'last_response_code' => $code,
                'last_error' => $result->error,
                'duration_ms' => $result->durationMs,
            ], $now);

            $this->events->dispatch(new DeliveryFailed(DeliveryMapper::delivery($delivery), $attempts[$delivery->id]));
        }

        return $this->result($deliveries, $outcome, DeliveryStatus::Failed, $result, $nextAttemptAt, $batchId);
    }

    /**
     * No attempt possible, ever: the endpoint is gone, the event was pruned, the
     * receiver said 410, or the schedule is spent.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function exhaust(array $deliveries, ?WebhookEndpoint $endpoint, string $reason, ?TransportResult $result = null): AttemptResult
    {
        $now = CarbonImmutable::now();

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Exhausted->value,
                'exhausted_at' => $now,
                'next_attempt_at' => null,
                'last_response_code' => $result === null ? $delivery->last_response_code : $result->responseCode,
                'last_error' => $result === null ? $reason : ($result->error ?? $reason),
                'duration_ms' => $result === null ? $delivery->duration_ms : $result->durationMs,
            ], $now);

            $this->metrics->histogram('webhooks.delivery.attempts', (float) $delivery->attempt, ['outcome' => 'exhausted']);
            $this->events->dispatch(new DeliveryExhausted(DeliveryMapper::delivery($delivery), $reason));
        }

        return $this->result($deliveries, $result === null ? AttemptOutcome::Cancelled : $result->outcome, DeliveryStatus::Exhausted, $result, null, null, $reason);
    }

    /**
     * The endpoint's transform could not produce a body: recorded as an
     * attempt (`transform_failed`, with the transformer's message) and
     * exhausted at once. The same event and expression fail the same way on
     * every retry, and the receiver did nothing wrong, so there is no retry and
     * no breaker failure.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function transformFailed(array $deliveries, WebhookEndpoint $endpoint, string $message): AttemptResult
    {
        $now = CarbonImmutable::now();
        $result = TransportResult::policy(AttemptOutcome::TransformFailed, mb_strcut('transform_failed: '.$message, 0, 1000, 'UTF-8'));
        $attempts = $this->recordAttempts($deliveries, $endpoint, $result, AttemptOutcome::TransformFailed, null, $now);

        $this->metrics->counter('webhooks.delivery.outcome', count($deliveries), ['outcome' => AttemptOutcome::TransformFailed->value, 'code_class' => 'none']);
        $this->announceAttempts($deliveries, $attempts);

        return $this->exhaust($deliveries, $endpoint, 'transform_failed', $result);
    }

    /**
     * The endpoint is paused: back to pending with the attempt number handed back.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function park(array $deliveries, WebhookEndpoint $endpoint): AttemptResult
    {
        $now = CarbonImmutable::now();

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Pending->value,
                'attempt' => max(0, $delivery->attempt - 1),
                'next_attempt_at' => null,
                'batch_id' => null,
            ], $now);
        }

        return $this->result($deliveries, AttemptOutcome::Deferred, DeliveryStatus::Pending, null, null, null, 'endpoint_paused');
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function cancel(array $deliveries, WebhookEndpoint $endpoint, string $reason, ?TransportResult $result = null, ?int $attempt = null): AttemptResult
    {
        $now = CarbonImmutable::now();

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Cancelled->value,
                'next_attempt_at' => null,
                'last_error' => $reason,
            ], $now);
        }

        return $this->result($deliveries, $result === null ? AttemptOutcome::Cancelled : $result->outcome, DeliveryStatus::Cancelled, $result, null, null, $reason);
    }

    /**
     * The breaker is open: wait until it admits a probe. No attempt row (the
     * noise would drown the real history) and the attempt number is handed back.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function wait(array $deliveries, WebhookEndpoint $endpoint, CarbonImmutable $closesAt): AttemptResult
    {
        $now = CarbonImmutable::now();

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Failed->value,
                'attempt' => max(0, $delivery->attempt - 1),
                'next_attempt_at' => $closesAt,
                'last_error' => 'breaker_open',
                'batch_id' => null,
            ], $now);
        }

        $this->metrics->counter('webhooks.delivery.outcome', count($deliveries), ['outcome' => AttemptOutcome::BreakerOpen->value, 'code_class' => 'none']);

        return $this->result($deliveries, AttemptOutcome::BreakerOpen, DeliveryStatus::Failed, null, $closesAt, null, 'breaker_open');
    }

    /**
     * This side's rate limit refused the slot: recorded as an attempt (the owner
     * asked for the limit and should see it bite), attempt number handed back.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    public function rateLimited(array $deliveries, WebhookEndpoint $endpoint, CarbonImmutable $retryAt): AttemptResult
    {
        $now = CarbonImmutable::now();
        $result = TransportResult::policy(AttemptOutcome::RateLimited, 'rate_limited');
        $attempts = $this->recordAttempts($deliveries, $endpoint, $result, AttemptOutcome::RateLimited, null, $now);

        $this->metrics->counter('webhooks.delivery.outcome', count($deliveries), ['outcome' => AttemptOutcome::RateLimited->value, 'code_class' => 'none']);

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Failed->value,
                'attempt' => max(0, $delivery->attempt - 1),
                'next_attempt_at' => $retryAt,
                'last_error' => 'rate_limited',
                'batch_id' => null,
            ], $now);

            $this->events->dispatch(new DeliveryAttempted(DeliveryMapper::delivery($delivery), $attempts[$delivery->id]));
            $this->events->dispatch(new DeliveryFailed(DeliveryMapper::delivery($delivery), $attempts[$delivery->id]));
        }

        return $this->result($deliveries, AttemptOutcome::RateLimited, DeliveryStatus::Failed, $result, $retryAt, null);
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     * @param  array<string, Attempt>  $attempts
     */
    private function succeed(array $deliveries, WebhookEndpoint $endpoint, TransportResult $result, array $attempts, CarbonImmutable $now): AttemptResult
    {
        $recovered = $this->breaker->recordSuccess($endpoint, $now, $result->responseCode);

        if ($recovered) {
            $this->events->dispatch(new EndpointRecovered($this->endpoints->endpoint($endpoint)));
        }

        foreach ($deliveries as $delivery) {
            $this->update($delivery, [
                'status' => DeliveryStatus::Delivered->value,
                'delivered_at' => $now,
                'next_attempt_at' => null,
                'last_response_code' => $result->responseCode,
                'last_error' => null,
                'duration_ms' => $result->durationMs,
            ], $now);

            $this->metrics->histogram('webhooks.delivery.attempts', (float) $delivery->attempt, ['outcome' => 'delivered']);

            $vo = DeliveryMapper::delivery($delivery);
            $this->events->dispatch(new DeliveryAttempted($vo, $attempts[$delivery->id]));
            $this->events->dispatch(new DeliverySucceeded($vo, $attempts[$delivery->id]));
        }

        return $this->result($deliveries, AttemptOutcome::Success, DeliveryStatus::Delivered, $result, null, null);
    }

    /**
     * One attempt row per delivery, the same response on each of a batch's.
     *
     * @param  list<WebhookDelivery>  $deliveries
     * @return array<string, Attempt> keyed by delivery id
     */
    private function recordAttempts(array $deliveries, WebhookEndpoint $endpoint, TransportResult $result, AttemptOutcome $outcome, ?string $batchId, CarbonImmutable $now): array
    {
        $attemptClass = $this->models->attempt();
        $startedAt = $now->subMilliseconds($result->durationMs);
        $out = [];

        foreach ($deliveries as $delivery) {
            $attempt = new $attemptClass;
            $attempt->fill([
                'delivery_id' => $delivery->id,
                'endpoint_id' => $endpoint->id,
                'partition' => $delivery->partition,
                'scope_key' => $delivery->scope_key,
                'batch_id' => $batchId,
                'attempt_number' => $delivery->attempt,
                'started_at' => $startedAt,
                'duration_ms' => $result->durationMs,
                'outcome' => $outcome,
                'response_code' => $result->responseCode,
                'response_excerpt' => $result->responseExcerpt,
                'error' => $result->error,
                'request_id' => $result->requestId,
                'created_at' => $now,
            ]);
            $attempt->save();

            $out[$delivery->id] = DeliveryMapper::attempt($attempt);
        }

        return $out;
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     * @param  array<string, Attempt>  $attempts
     */
    private function announceAttempts(array $deliveries, array $attempts): void
    {
        foreach ($deliveries as $delivery) {
            $this->events->dispatch(new DeliveryAttempted(DeliveryMapper::delivery($delivery), $attempts[$delivery->id]));
        }
    }

    /**
     * `Retry-After` when the receiver sent one, capped at the schedule's largest
     * wait; the backoff otherwise. Null once the schedule is spent: a receiver
     * answering 429 forever does not keep a delivery alive forever.
     *
     * @param  list<int>|null  $schedule
     */
    private function rateLimitDelay(TransportResult $result, int $attempt, ?array $schedule, CarbonImmutable $now): ?int
    {
        if ($this->backoff->spent($attempt, $schedule)) {
            return null;
        }

        $requested = RetryAfter::seconds($result->retryAfter, $now);

        if ($requested !== null) {
            return min($requested, $this->backoff->maxDelay($schedule));
        }

        return $this->backoff->delayAfter($attempt, $schedule);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function update(WebhookDelivery $delivery, array $columns, CarbonImmutable $now): void
    {
        $columns['updated_at'] = $now;

        $deliveryClass = $this->models->delivery();
        $deliveryClass::query()->whereKey($delivery->id)->update($columns);

        $delivery->forceFill($columns)->syncOriginal();
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     */
    private function result(array $deliveries, AttemptOutcome $outcome, DeliveryStatus $status, ?TransportResult $result, ?CarbonImmutable $nextAttemptAt, ?string $batchId, ?string $error = null): AttemptResult
    {
        $first = $deliveries[0];

        return new AttemptResult(
            outcome: $outcome,
            status: $status,
            responseCode: $result?->responseCode,
            durationMs: $result === null ? 0 : $result->durationMs,
            error: $error ?? $result?->error,
            nextAttemptAt: $nextAttemptAt,
            deliveryId: $batchId ?? $first->id,
            attempt: $first->attempt,
        );
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     */
    private function highestAttempt(array $deliveries): int
    {
        $highest = 0;

        foreach ($deliveries as $delivery) {
            $highest = max($highest, $delivery->attempt);
        }

        return $highest;
    }

    private function codeClass(?int $code): string
    {
        if ($code === null) {
            return 'none';
        }

        return intdiv($code, 100).'xx';
    }
}
