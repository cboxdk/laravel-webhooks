<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Events\EndpointDisabled;
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
    $this->scope = Scope::of('org_1');
});

it('refuses the request over the per-minute limit, records it and retries in the next slot', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], rateLimitPerMinute: 2);

    foreach ([1, 2, 3] as $i) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['i' => $i]));
    }

    $third = WebhookDelivery::query()->orderByDesc('sequence')->firstOrFail();

    expect(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(2)
        ->and($third->status)->toBe(DeliveryStatus::Failed)
        ->and($third->attempt)->toBe(0)
        ->and($third->last_error)->toBe('rate_limited')
        ->and($third->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:30')
        ->and(WebhookAttempt::query()->where('outcome', AttemptOutcome::RateLimited->value)->count())->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 2);

    CarbonImmutable::setTestNow('2026-10-30 12:01:00');
    $this->deliverPendingWebhooks();

    expect($third->refresh()->status)->toBe(DeliveryStatus::Delivered)->and($third->attempt)->toBe(1);
});

it('disables an endpoint that has not succeeded since its first failure past the window', function (): void {
    config(['webhooks.disable_after_seconds' => 3600, 'webhooks.breaker.failure_threshold' => 100]);
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    Event::fake([EndpointDisabled::class]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'a'));
    $endpoint = WebhookEndpoint::query()->firstOrFail();
    expect($endpoint->first_failure_at?->toDateTimeString())->toBe('2026-10-30 12:00:00')->and($endpoint->status)->toBe(EndpointStatus::Active);

    // Still inside the window: the sweep leaves it active.
    CarbonImmutable::setTestNow('2026-10-30 12:30:00');
    expect($this->deliverPendingWebhooks()->endpointsDisabled)->toBe(0);

    // Past the window, with a second pending delivery waiting.
    CarbonImmutable::setTestNow('2026-10-30 13:00:01');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'b'));

    $endpoint->refresh();
    expect($endpoint->status)->toBe(EndpointStatus::Disabled)
        ->and($endpoint->disabled_reason)->toBe('unreachable')
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Cancelled->value)->count())->toBe(2);
    Event::assertDispatched(EndpointDisabled::class, fn (EndpointDisabled $e): bool => $e->reason->value === 'unreachable' && $e->endpoint->id === $registered->endpoint->id);

    // Resume clears the state and the owner starts fresh.
    $resumed = Webhooks::endpoints()->resume($this->scope, $registered->endpoint->id);
    expect($resumed?->status)->toBe(EndpointStatus::Active)
        ->and($resumed?->disabledReason)->toBeNull()
        ->and($resumed?->health->firstFailureAt)->toBeNull();
});

it('disables through the sweep as well, for an endpoint nothing has tried since', function (): void {
    config(['webhooks.disable_after_seconds' => 60, 'webhooks.breaker.failure_threshold' => 100]);
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    CarbonImmutable::setTestNow('2026-10-30 12:05:00');
    // Hold the retry back so the sweep's auto-disable step, not a failed attempt, is what disables.
    WebhookDelivery::query()->update(['next_attempt_at' => CarbonImmutable::now()->addHour()]);

    expect($this->deliverPendingWebhooks()->endpointsDisabled)->toBe(1)
        ->and(WebhookEndpoint::query()->firstOrFail()->status)->toBe(EndpointStatus::Disabled)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Cancelled);
});

it('resets the first failure on any success so a flapping endpoint is not disabled', function (): void {
    config(['webhooks.disable_after_seconds' => 60]);
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)->push('', 500)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'a'));
    CarbonImmutable::setTestNow('2026-10-30 12:00:10');
    $this->deliverPendingWebhooks();

    expect(WebhookEndpoint::query()->firstOrFail()->first_failure_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-10-30 12:05:00');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'b'));

    expect(WebhookEndpoint::query()->firstOrFail()->first_failure_at?->toDateTimeString())->toBe('2026-10-30 12:05:00')
        ->and(WebhookEndpoint::query()->firstOrFail()->status)->toBe(EndpointStatus::Active);
});
