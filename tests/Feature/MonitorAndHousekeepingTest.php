<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Erasure;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Contracts\Pruner;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Models\WebhookInboundDelivery;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\Webhooks\ValueObjects\Thresholds;
use Cbox\Webhooks\ValueObjects\Window;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1');
});

it('reports the overview numbers', function (): void {
    Http::fake([
        'receiver.test/good' => Http::response('', 200),
        'receiver.test/bad' => Http::response('', 500),
    ]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/good', ['user.created']);
    $bad = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/bad', ['user.created']);
    $paused = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/paused', ['user.created']);
    Webhooks::endpoints()->pause($this->scope, $paused->endpoint->id);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    CarbonImmutable::setTestNow('2026-10-30 12:30:00');
    $overview = app(Monitor::class)->overview();

    expect($overview->pending)->toBe(1)
        ->and($overview->inFlight)->toBe(0)
        ->and($overview->dueOverdue)->toBe(1)
        ->and($overview->delivered24h)->toBe(1)
        ->and($overview->failed24h)->toBe(1)
        ->and($overview->exhausted24h)->toBe(0)
        ->and($overview->successRate24h)->toBe(1.0)
        ->and($overview->oldestPendingAgeSeconds)->toBe(1800)
        ->and($overview->endpointsActive)->toBe(2)
        ->and($overview->endpointsPaused)->toBe(1)
        ->and($overview->toArray()['endpoints']['tripped'])->toBe(0);

    expect(app(Monitor::class)->isHealthy(new Thresholds(maxDueOverdue: 0)))->toBeFalse()
        ->and(app(Monitor::class)->isHealthy(new Thresholds(maxDueOverdue: 5, maxOldestPendingAgeSeconds: 3600)))->toBeTrue()
        ->and(app(Monitor::class)->isHealthy(new Thresholds(maxDueOverdue: 5, maxOldestPendingAgeSeconds: 60)))->toBeFalse();

    $scoped = app(Monitor::class)->overview(Scope::of('org_9'));
    expect($scoped->pending)->toBe(0)->and($scoped->endpointsActive)->toBe(0);

    $failing = app(Monitor::class)->failingEndpoints(5);
    expect($failing)->toHaveCount(1)->and($failing[0]->endpointId)->toBe($bad->endpoint->id);
});

it('computes endpoint stats with percentiles from recent attempts', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    foreach ([1, 2, 3, 4] as $i) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['i' => $i]));
    }

    $durations = [10, 20, 30, 400];
    foreach (WebhookAttempt::query()->orderBy('created_at')->get() as $index => $attempt) {
        $attempt->forceFill(['duration_ms' => $durations[$index]])->save();
    }

    $stats = app(Monitor::class)->endpointStats($registered->endpoint->id, Window::lastHours(1));

    expect($stats->delivered)->toBe(4)
        ->and($stats->successRate)->toBe(1.0)
        ->and($stats->p50DurationMs)->toBe(20)
        ->and($stats->p95DurationMs)->toBe(400)
        ->and($stats->lastSuccessAt)->not->toBeNull();

    $slowest = app(Monitor::class)->slowestEndpoints(3, Window::lastHours(1));
    expect($slowest)->toHaveCount(1)->and($slowest[0]->endpointId)->toBe($registered->endpoint->id);

    expect(app(Monitor::class)->endpointStats('missing', Window::lastHours(1))->delivered)->toBe(0);
});

it('lists, finds with attempts and retries deliveries in scope only', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));
    $delivery = WebhookDelivery::query()->firstOrFail();

    $deliveries = app(Deliveries::class);

    expect($deliveries->find(Scope::of('org_2'), $delivery->id))->toBeNull()
        ->and($deliveries->find($this->scope, $delivery->id)?->attempts)->toHaveCount(1)
        ->and($deliveries->list($this->scope, new DeliveryFilter(endpointId: $registered->endpoint->id, status: DeliveryStatus::Failed)))->toHaveCount(1)
        ->and($deliveries->list($this->scope, new DeliveryFilter(type: 'invoice.paid')))->toHaveCount(0)
        ->and($deliveries->retry(Scope::of('org_2'), $delivery->id))->toBeFalse()
        ->and($deliveries->retry($this->scope, $delivery->id))->toBeTrue()
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($deliveries->retry($this->scope, $delivery->id))->toBeFalse();
});

it('prunes by retention and erases a scope', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'old'));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_2'), [], 'other'));

    WebhookInboundDelivery::query()->create([
        'endpoint_name' => 'x', 'delivery_id' => 'd1', 'type' => 'user.created', 'payload' => [], 'received_at' => CarbonImmutable::now(), 'status' => 'processed',
    ]);

    CarbonImmutable::setTestNow('2026-12-15 12:00:00');
    $report = app(Pruner::class)->prune();

    expect($report->deliveries)->toBe(1)
        ->and($report->attempts)->toBe(1)
        ->and($report->events)->toBe(2)
        ->and($report->inbound)->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'a'));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_2'), [], 'b'));

    expect(app(Erasure::class)->forget($this->scope))->toBe(3)
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(0);
});

it('keeps failed deliveries past the delivered retention until the failed retention', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    config(['webhooks.retry.schedule' => []]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));
    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Exhausted);

    CarbonImmutable::setTestNow('2026-12-15 12:00:00');
    expect(app(Pruner::class)->prune()->deliveries)->toBe(0);

    CarbonImmutable::setTestNow('2027-02-15 12:00:00');
    expect(app(Pruner::class)->prune()->deliveries)->toBe(1);
});
