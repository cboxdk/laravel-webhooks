<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Jobs\DeliverWebhook;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Bus\Dispatcher;
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

    (new DeliverWebhook($delivery->id, 0, 900))->handle(app(Deliverer::class));

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('fixes the job timeout at dispatch from the transport budget', function (): void {
    // The budget covers the highest timeout an endpoint may choose, not just the default.
    config(['webhooks.http.connect_timeout' => 3, 'webhooks.http.timeout' => 7, 'webhooks.http.max_endpoint_timeout' => 5]);
    Bus::fake([DeliverWebhook::class]);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));

    Bus::assertDispatched(DeliverWebhook::class, fn (DeliverWebhook $job): bool => $job->timeout === 25 && $job->uniqueFor === 900);
});

/**
 * One noisy owner with far more due retries than the sweep may take, and a
 * quiet one whose few retries came due later.
 *
 * @return array{noisy: Scope, quiet: Scope}
 */
function noisyAndQuiet(int $noisy, int $quiet): array
{
    Http::fake(['receiver.test/*' => Http::response('', 500)]);

    // Keep the breaker out of it: the point is many retries due at once, not parked ones.
    config(['webhooks.breaker.failure_threshold' => 1000]);

    $scopes = ['noisy' => Scope::of('shop', 'org_a'), 'quiet' => Scope::of('desk', 'org_b')];

    Webhooks::endpoints()->register($scopes['noisy'], new NewEndpoint('https://receiver.test/noisy', ['user.created']));
    Webhooks::endpoints()->register($scopes['quiet'], new NewEndpoint('https://receiver.test/quiet', ['user.created']));

    for ($i = 0; $i < $noisy; $i++) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scopes['noisy'], ['id' => $i]));
    }

    // The quiet owner's retries come due a second later, so oldest-first puts them last.
    CarbonImmutable::setTestNow('2026-10-30 12:00:01');

    for ($i = 0; $i < $quiet; $i++) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scopes['quiet'], ['id' => $i]));
    }

    Bus::fake([DeliverWebhook::class]);
    CarbonImmutable::setTestNow('2026-10-30 12:01:00');

    return $scopes;
}

/** How many of the jobs the sweep pushed belong to each scope key. */
function queuedPerScope(): array
{
    $ids = Bus::dispatched(DeliverWebhook::class)->map(fn (DeliverWebhook $job): string => $job->deliveryId)->all();

    return WebhookDelivery::query()->whereIn('id', $ids)->get()->countBy('scope_key')->all();
}

it('takes the oldest rows across every scope by default, so a noisy owner fills the tick', function (): void {
    config(['webhooks.sweep_limit' => 10]);
    noisyAndQuiet(noisy: 30, quiet: 3);

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(10)
        ->and(queuedPerScope())->toBe(['shop' => 10]);
});

it('serves the quiet owner in the same tick as the noisy one when the sweep is fair by scope', function (): void {
    config(['webhooks.sweep_limit' => 10, 'webhooks.sweep_fairness' => 'scope']);
    noisyAndQuiet(noisy: 30, quiet: 3);

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(10);

    $queued = queuedPerScope();
    ksort($queued);

    // All three of the quiet owner's, and the rest of the tick to the noisy one: nothing is wasted.
    expect($queued)->toBe(['desk' => 3, 'shop' => 7]);

    // Within an owner the order is still oldest first.
    $noisyQueued = WebhookDelivery::query()->where('scope_key', 'shop')->where('next_attempt_at', '>', CarbonImmutable::now())->pluck('sequence')->sort()->values()->all();
    expect($noisyQueued)->toBe([1, 2, 3, 4, 5, 6, 7]);

    // The next tick carries on with what is left: only the noisy owner's.
    Bus::fake([DeliverWebhook::class]);
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(10)
        ->and(queuedPerScope())->toBe(['shop' => 10]);
});

it('takes turns over the partition when that is the tenant', function (): void {
    config(['webhooks.sweep_limit' => 4, 'webhooks.sweep_fairness' => 'partition']);

    // A second owner inside the noisy partition: by scope it would get its own turn, by partition it shares one.
    $bus = Bus::getFacadeRoot();
    $scopes = noisyAndQuiet(noisy: 10, quiet: 2);
    Bus::swap(app(Dispatcher::class));
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $sibling = Scope::of('blog', 'org_a');
    $this->registerWebhookEndpoint($sibling, 'https://receiver.test/sibling', ['user.created']);
    CarbonImmutable::setTestNow('2026-10-30 12:00:02');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $sibling, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $sibling, ['id' => 2]));
    Bus::fake([DeliverWebhook::class]);
    CarbonImmutable::setTestNow('2026-10-30 12:01:00');

    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(4);

    $partitions = WebhookDelivery::query()
        ->whereIn('id', Bus::dispatched(DeliverWebhook::class)->map(fn (DeliverWebhook $job): string => $job->deliveryId)->all())
        ->get()->countBy('partition')->all();
    ksort($partitions);

    expect($partitions)->toBe(['org_a' => 2, 'org_b' => 2])
        ->and($scopes['quiet']->partition)->toBe('org_b');
});

it('rescues stranded in-flight rows fairly as well, and falls back to oldest first for an unknown setting', function (): void {
    config(['webhooks.sweep_limit' => 4, 'webhooks.sweep_fairness' => 'scope']);
    noisyAndQuiet(noisy: 6, quiet: 2);

    WebhookDelivery::query()->update(['status' => DeliveryStatus::InFlight->value, 'next_attempt_at' => null]);
    WebhookDelivery::query()->where('scope_key', 'shop')->update(['updated_at' => '2026-10-30 11:00:00']);
    WebhookDelivery::query()->where('scope_key', 'desk')->update(['updated_at' => '2026-10-30 11:30:00']);

    $report = $this->deliverPendingWebhooks();

    expect($report->strandedRescued)->toBe(4);

    $rescued = WebhookDelivery::query()->where('last_error', 'stranded')->get()->countBy('scope_key')->all();
    ksort($rescued);
    expect($rescued)->toBe(['desk' => 2, 'shop' => 2]);

    config(['webhooks.sweep_fairness' => 'nonsense']);
    expect($this->deliverPendingWebhooks()->strandedRescued)->toBe(4)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::InFlight->value)->count())->toBe(0);
});
