<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Delivery\CircuitBreaker;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Events\EndpointRecovered;
use Cbox\Webhooks\Events\EndpointTripped;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    config(['webhooks.breaker.failure_threshold' => 3, 'webhooks.breaker.cooldown_seconds' => 60, 'webhooks.breaker.max_cooldown_seconds' => 200]);
    $this->scope = Scope::of('org_1');
    $this->registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
});

function publishN(Scope $scope, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['i' => $i]));
    }
}

it('opens after the threshold of consecutive failures and parks later deliveries without an attempt', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 502)]);
    Event::fake([EndpointTripped::class]);

    publishN($this->scope, 3);

    $endpoint = WebhookEndpoint::query()->firstOrFail();
    expect($endpoint->consecutive_failures)->toBe(3)
        ->and($endpoint->trip_count)->toBe(1)
        ->and($endpoint->circuit_opened_at?->toDateTimeString())->toBe('2026-10-30 12:00:00');
    Event::assertDispatchedTimes(EndpointTripped::class, 1);
    Event::assertDispatched(EndpointTripped::class, fn (EndpointTripped $e): bool => $e->closesAt->toDateTimeString() === '2026-10-30 12:01:00');

    // The fourth delivery costs no HTTP and no attempt row; it waits for the breaker.
    publishN($this->scope, 1);
    $fourth = WebhookDelivery::query()->orderByDesc('sequence')->firstOrFail();

    expect($fourth->status)->toBe(DeliveryStatus::Failed)
        ->and($fourth->attempt)->toBe(0)
        ->and($fourth->last_error)->toBe('breaker_open')
        ->and($fourth->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:01:00')
        ->and(WebhookAttempt::query()->count())->toBe(3);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 3);

    expect(Webhooks::endpoints()->health($this->scope, $endpoint->id)?->isTripped())->toBeTrue();
});

it('admits one probe after the cooldown, closes on success and announces the recovery', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 502)->push('', 502)->push('', 502)->whenEmpty(Http::response('ok', 200))]);
    Event::fake([EndpointRecovered::class]);

    publishN($this->scope, 3);
    publishN($this->scope, 1); // parked, waits until 12:01:00

    CarbonImmutable::setTestNow('2026-10-30 12:01:00');
    $report = $this->deliverPendingWebhooks();

    // Every failed delivery was due; the first claimed one probes, succeeds and closes the breaker,
    // after which the rest go through normally.
    $endpoint = WebhookEndpoint::query()->firstOrFail();
    expect($report->retriesQueued)->toBe(4)
        ->and($endpoint->circuit_opened_at)->toBeNull()
        ->and($endpoint->consecutive_failures)->toBe(0)
        ->and($endpoint->trip_count)->toBe(0)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBeGreaterThanOrEqual(2);
    Event::assertDispatchedTimes(EndpointRecovered::class, 1);
});

it('re-opens with a doubled cooldown when the probe fails, up to the cap', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 502)]);

    publishN($this->scope, 3);

    CarbonImmutable::setTestNow('2026-10-30 12:01:00');
    $this->deliverPendingWebhooks();

    $endpoint = WebhookEndpoint::query()->firstOrFail();
    expect($endpoint->trip_count)->toBe(2)
        ->and($endpoint->circuit_opened_at?->toDateTimeString())->toBe('2026-10-30 12:01:00')
        ->and(app(CircuitBreaker::class)->closesAt($endpoint)?->toDateTimeString())->toBe('2026-10-30 12:03:00');

    // Exactly one HTTP request was made for the probe; the other two due deliveries waited.
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 4);

    CarbonImmutable::setTestNow('2026-10-30 12:03:00');
    $this->deliverPendingWebhooks();
    $endpoint->refresh();
    expect($endpoint->trip_count)->toBe(3)
        ->and(app(CircuitBreaker::class)->closesAt($endpoint)?->toDateTimeString())->toBe('2026-10-30 12:06:20');

    // 60 * 2^3 = 480 > the 200s cap.
    CarbonImmutable::setTestNow('2026-10-30 12:06:20');
    $this->deliverPendingWebhooks();
    $endpoint->refresh();
    expect($endpoint->trip_count)->toBe(4)
        ->and(app(CircuitBreaker::class)->closesAt($endpoint)?->toDateTimeString())->toBe('2026-10-30 12:09:40');
});

it('admits exactly one worker as the probe when several race for it', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 502)]);
    publishN($this->scope, 3);

    CarbonImmutable::setTestNow('2026-10-30 12:01:00');
    $endpointA = WebhookEndpoint::query()->firstOrFail();
    $endpointB = WebhookEndpoint::query()->firstOrFail();
    $breaker = app(CircuitBreaker::class);

    expect($breaker->admit($endpointA, CarbonImmutable::now()))->toBeTrue()
        ->and($breaker->admit($endpointB, CarbonImmutable::now()))->toBeFalse();
});

it('does not count a rate limit or a blocked URL against the breaker', function (): void {
    config(['webhooks.breaker.failure_threshold' => 1]);
    Http::fake(['receiver.test/*' => Http::response('', 429)]);

    publishN($this->scope, 2);

    expect(WebhookEndpoint::query()->firstOrFail()->circuit_opened_at)->toBeNull();
});

it('does not spend the retry budget while waiting on the breaker', function (): void {
    config(['webhooks.retry.schedule' => [10]]);
    Http::fake(['receiver.test/*' => Http::response('', 502)]);
    publishN($this->scope, 3);

    // The breaker is open; a fresh delivery waits without consuming its single attempt.
    publishN($this->scope, 1);
    $fresh = WebhookDelivery::query()->orderByDesc('sequence')->firstOrFail();
    expect($fresh->attempt)->toBe(0)->and($fresh->status)->toBe(DeliveryStatus::Failed);

    $result = app(Deliverer::class)->deliver($fresh->id, 0);
    expect($result?->outcome)->toBe(AttemptOutcome::BreakerOpen)->and($fresh->refresh()->attempt)->toBe(0);
});
