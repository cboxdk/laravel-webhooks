<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Delivery\HttpTransport;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Jobs\DeliverWebhook;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1');
    $this->registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
});

/** Publishes with the bus faked (so the row stays pending), then hands the real bus back. */
function pendingDelivery(Scope $scope): WebhookDelivery
{
    $bus = Bus::getFacadeRoot();
    Bus::fake([DeliverWebhook::class]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 1]));
    Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->expectedAttempt === 0);
    Bus::swap($bus);

    return WebhookDelivery::query()->firstOrFail();
}

it('lets exactly one of two jobs for the same attempt send', function (): void {
    $delivery = pendingDelivery($this->scope);

    $first = app(Deliverer::class)->deliver($delivery->id, 0);
    $second = app(Deliverer::class)->deliver($delivery->id, 0);

    expect($first?->succeeded())->toBeTrue()
        ->and($second)->toBeNull()
        ->and($delivery->refresh()->attempt)->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
});

it('refuses to claim a delivered delivery, a cancelled one, or a stale attempt number', function (): void {
    $delivery = pendingDelivery($this->scope);
    app(Deliverer::class)->deliver($delivery->id, 0);

    expect(app(Deliverer::class)->deliver($delivery->id, 1))->toBeNull()
        ->and(app(Deliverer::class)->deliver($delivery->id, 0))->toBeNull();

    WebhookDelivery::query()->update(['status' => 'cancelled']);
    expect(app(Deliverer::class)->deliver($delivery->id, 1))->toBeNull();

    expect(app(Deliverer::class)->deliver('01NOPE000000000000000000000', 0))->toBeNull();
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
});

it('pushes one unique job per delivery, keyed by the delivery id', function (): void {
    Bus::fake([DeliverWebhook::class]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    $delivery = WebhookDelivery::query()->firstOrFail();

    Bus::assertDispatched(DeliverWebhook::class, function (DeliverWebhook $job) use ($delivery): bool {
        return $job->uniqueId() === $delivery->id && $job->uniqueFor === 900 && $job->tries === 1;
    });
});

it('rescues a pending delivery whose job was lost once it is older than the stranded window', function (): void {
    $delivery = pendingDelivery($this->scope);

    CarbonImmutable::setTestNow('2026-10-30 12:10:00');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(0);

    CarbonImmutable::setTestNow('2026-10-30 12:15:01');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('rescues an in-flight delivery a worker never settled', function (): void {
    $delivery = pendingDelivery($this->scope);
    WebhookDelivery::query()->update(['status' => 'in_flight', 'attempt' => 1, 'updated_at' => CarbonImmutable::now()]);

    CarbonImmutable::setTestNow('2026-10-30 12:16:00');
    $report = $this->deliverPendingWebhooks();

    expect($report->strandedRescued)->toBe(1)
        ->and($report->retriesQueued)->toBe(1)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempt)->toBe(2);
});

it('terminalises orphans in one pass so they never occupy a sweep slot', function (): void {
    pendingDelivery($this->scope);
    pendingDelivery($this->scope);
    WebhookEndpoint::query()->delete();

    $report = $this->deliverPendingWebhooks();

    expect($report->orphansTerminalised)->toBe(2)
        ->and($report->retriesQueued)->toBe(0)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Exhausted->value)->count())->toBe(2)
        ->and(WebhookDelivery::query()->firstOrFail()->last_error)->toBe('endpoint_deleted');
});

it('leases a due delivery so two sweeps in the same minute push it once', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    $delivery = WebhookDelivery::query()->firstOrFail();

    Bus::fake([DeliverWebhook::class]);
    CarbonImmutable::setTestNow('2026-10-30 12:00:10');

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1)
        ->and($this->deliverPendingWebhooks()->retriesQueued)->toBe(0)
        ->and($delivery->refresh()->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:15:10');
    Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
});

it('sweeps across every scope and skips paused and batching endpoints', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $paused = $this->registerWebhookEndpoint(Scope::of('org_2', 'env_9'), 'https://receiver.test/paused', ['user.created']);
    $this->registerWebhookEndpoint(Scope::of('org_3', 'env_9'), 'https://receiver.test/org3', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_2', 'env_9'), ['id' => 2]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_3', 'env_9'), ['id' => 3]));
    Webhooks::endpoints()->pause(Scope::of('org_2', 'env_9'), $paused->endpoint->id);

    Bus::fake([DeliverWebhook::class]);
    CarbonImmutable::setTestNow('2026-10-30 12:00:10');

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(2);
});

it('respects the sweep limit and orders by next attempt time', function (): void {
    config(['webhooks.sweep_limit' => 1]);
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 2]));

    Bus::fake([DeliverWebhook::class]);
    CarbonImmutable::setTestNow('2026-10-30 12:00:10');

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1);
    Bus::assertDispatchedTimes(DeliverWebhook::class, 1);
});

it('runs the job through the deliverer with the attempt it was pushed with', function (): void {
    $delivery = pendingDelivery($this->scope);

    (new DeliverWebhook($delivery->id, 0, 900))->handle(app(Deliverer::class), app(HttpTransport::class));

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});
