<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Jobs\DeliverWebhook;
use Cbox\Webhooks\Jobs\FlushEndpointBatches;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
    $this->other = Scope::of('shop', 'org_b');
});

/** Publish `$count` user.created events in the scope, one second apart. */
function publishUsers(Scope $scope, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_'.$i]));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
    }
}

it('replays only the named events, however far back they lie', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    publishUsers($this->scope, 3);
    $ids = WebhookEvent::query()->orderBy('occurred_at')->pluck('id')->all();

    CarbonImmutable::setTestNow('2027-06-01 00:00:00');

    $request = ReplayRequest::events([$ids[0], $ids[2]]);

    expect($request->since)->toBeNull()
        ->and(app(Endpoints::class)->countReplay($this->scope, $registered->endpoint->id, $request))->toBe(2)
        ->and(app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, $request))->toBe(2)
        ->and(WebhookDelivery::query()->where('event_id', $ids[0])->count())->toBe(2)
        ->and(WebhookDelivery::query()->where('event_id', $ids[1])->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', $ids[2])->count())->toBe(2);
});

it('keeps named events inside the scope and the subscription', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    $this->registerWebhookEndpoint($this->other, 'https://receiver.test/other', ['user.created', 'invoice.paid']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->other, ['id' => 'theirs']));
    app(Publisher::class)->publish(new OutboundEvent('invoice.paid', $this->scope, ['id' => 'unsubscribed']));

    $ids = WebhookEvent::query()->pluck('id')->all();
    $request = new ReplayRequest(eventIds: $ids);

    expect(app(Endpoints::class)->countReplay($this->scope, $registered->endpoint->id, $request))->toBe(0)
        ->and(app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, $request))->toBe(0)
        ->and(app(Endpoints::class)->countReplay($this->other, $registered->endpoint->id, $request))->toBe(0);
});

it('refuses a request bounded by neither a time nor event ids', function (): void {
    expect(fn () => new ReplayRequest)->toThrow(InvalidArgumentException::class);
});

it('skips events already delivered or still under way when asked for the undelivered only', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 200)->push('', 500)->push('', 200)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    $id = $registered->endpoint->id;

    // Delivered, failed and awaiting retry, delivered.
    publishUsers($this->scope, 3);
    $events = WebhookEvent::query()->orderBy('occurred_at')->pluck('id')->all();

    // One exhausted, one pending, one in flight: only the exhausted one is not under way.
    $deliveryFor = static fn (string $eventId): WebhookDelivery => WebhookDelivery::query()->where('event_id', $eventId)->firstOrFail();

    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    config(['webhooks.breaker.failure_threshold' => 1000]);
    publishUsers($this->scope, 3);
    $more = array_values(array_diff(WebhookEvent::query()->orderBy('occurred_at')->pluck('id')->all(), $events));
    $deliveryFor($more[0])->forceFill(['status' => DeliveryStatus::Exhausted])->save();
    $deliveryFor($more[1])->forceFill(['status' => DeliveryStatus::Pending])->save();
    $deliveryFor($more[2])->forceFill(['status' => DeliveryStatus::InFlight])->save();

    $since = CarbonImmutable::parse('2026-10-30 00:00:00');
    $endpoints = app(Endpoints::class);

    expect($endpoints->countReplay($this->scope, $id, new ReplayRequest($since)))->toBe(6)
        ->and($endpoints->countReplay($this->scope, $id, new ReplayRequest($since, onlyUndelivered: true)))->toBe(1);

    Bus::fake([DeliverWebhook::class]);

    expect($endpoints->replay($this->scope, $id, new ReplayRequest($since, onlyUndelivered: true)))->toBe(1)
        ->and(WebhookDelivery::query()->where('event_id', $more[0])->count())->toBe(2);

    // Now under way, so a second run finds nothing.
    expect($endpoints->countReplay($this->scope, $id, new ReplayRequest($since, onlyUndelivered: true)))->toBe(0);
});

it('counts exactly what replay would create and writes nothing', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    publishUsers($this->scope, 5);
    $before = WebhookDelivery::query()->count();

    $request = new ReplayRequest(CarbonImmutable::parse('2026-10-30 12:00:01'), CarbonImmutable::parse('2026-10-30 12:00:03'), limit: 2);

    expect(app(Endpoints::class)->countReplay($this->scope, $registered->endpoint->id, $request))->toBe(2)
        ->and(WebhookDelivery::query()->count())->toBe($before)
        ->and(app(Endpoints::class)->countReplay($this->other, $registered->endpoint->id, $request))->toBe(0)
        ->and(app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, $request))->toBe(2);
});

it('fills the limit with matching events even when unsubscribed ones come first', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('invoice.paid', $this->scope, ['id' => 'inv_1']));
    app(Publisher::class)->publish(new OutboundEvent('invoice.paid', $this->scope, ['id' => 'inv_2']));
    CarbonImmutable::setTestNow('2026-10-30 12:00:05');
    publishUsers($this->scope, 2);

    $request = new ReplayRequest(CarbonImmutable::parse('2026-10-30 00:00:00'), limit: 2);

    expect(app(Endpoints::class)->countReplay($this->scope, $registered->endpoint->id, $request))->toBe(2);
});

it('paces a replay: delivery i waits floor(i * 60 / perMinute) seconds', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    publishUsers($this->scope, 4);

    Bus::fake([DeliverWebhook::class]);

    $count = app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, new ReplayRequest(CarbonImmutable::parse('2026-10-30 00:00:00'), perMinute: 40));

    $delays = [];
    Bus::assertDispatched(DeliverWebhook::class, function (DeliverWebhook $job) use (&$delays): bool {
        $delays[] = $job->delay;

        return true;
    });

    expect($count)->toBe(4)->and($delays)->toBe([null, 1, 3, 4]);

    // The paced rows say when they are due, so the sweep does not send them early.
    $due = WebhookDelivery::query()->where('status', DeliveryStatus::Pending->value)->orderBy('sequence')->pluck('next_attempt_at')->map(fn ($at) => $at?->toDateTimeString())->all();
    expect($due)->toBe([null, '2026-10-30 12:00:05', '2026-10-30 12:00:07', '2026-10-30 12:00:08']);
});

it('leaves a paced delivery to its delayed job until the stranded window has passed its due time', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    publishUsers($this->scope, 2);

    Bus::fake([DeliverWebhook::class]);
    app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, new ReplayRequest(CarbonImmutable::parse('2026-10-30 00:00:00'), perMinute: 1));
    Bus::fake([DeliverWebhook::class]);

    // Past the stranded window from creation, but the second row is not due for a minute after that.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(901));
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1);
});

it('flushes a batching endpoint once for a replay, paced or not', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], batchSize: 10, batchWindowSeconds: 30);
    publishUsers($this->scope, 3);

    Bus::fake([FlushEndpointBatches::class]);

    expect(app(Endpoints::class)->replay($this->scope, $registered->endpoint->id, new ReplayRequest(CarbonImmutable::parse('2026-10-30 00:00:00'), perMinute: 60)))->toBe(3);

    Bus::assertDispatchedTimes(FlushEndpointBatches::class, 1);
});
