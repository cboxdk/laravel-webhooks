<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Events\DeliveryQueued;
use Cbox\Webhooks\Events\DeliverySucceeded;
use Cbox\Webhooks\Events\EventPublished;
use Cbox\Webhooks\Exceptions\PayloadTooLarge;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function publishUserCreated(Scope $scope, ?string $key = null, array $payload = ['id' => 'usr_1']): PublishedEvent
{
    return app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, $payload, $key));
}

it('publishes one event, fans it out to every matching endpoint and delivers after commit', function (): void {
    $scope = Scope::of('org_1', 'env_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);
    $this->registerWebhookEndpoint($scope, 'https://other.test/hooks', ['user.*']);
    $this->registerWebhookEndpoint(Scope::of('org_2', 'env_1'), 'https://receiver.test/org2', ['*']);

    $published = publishUserCreated($scope);

    expect($published->duplicate)->toBeFalse()
        ->and($published->deliveries)->toBe(2)
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(2);

    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.created', fn (Request $request, array $envelope): bool => $envelope['data'] === ['id' => 'usr_1']
        && $envelope['sequence'] === 1
        && $envelope['attempt'] === 1
        && $envelope['scope'] === ['partition' => 'env_1', 'key' => 'org_1']
        && $envelope['id'] === $published->id
        && $request->hasHeader('X-Webhook-Event', 'user.created')
        && $request->hasHeader('X-Webhook-Attempt', '1')
        && $request->hasHeader('X-Webhook-Sequence', '1')
        && $request->hasHeader('Idempotency-Key', $envelope['delivery_id'])
        && $request->hasHeader('X-Webhook-Delivery', $envelope['delivery_id'])
        && str_starts_with($request->header('User-Agent')[0], 'Cbox-Webhooks/'));
    $this->assertWebhookDelivered('https://other.test/hooks', 'user.created');
    $this->assertWebhookNotDelivered('https://receiver.test/org2');
});

it('signs every delivery with the cbox scheme so the signature package verifies it', function (): void {
    $scope = Scope::of('org_1');
    $registered = $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    publishUserCreated($scope);

    [$request] = $this->recordedWebhookRequests('https://receiver.test/hooks')[0];

    $this->fakeWebhookEndpoint('inbound', 'cbox', $registered->secret);
    $verified = app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()));

    expect($verified->scheme)->toBe('cbox');
});

it('uses the delivery id as the standard-webhooks message id', function (): void {
    $scope = Scope::of('org_1');
    $registered = $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created'], scheme: 'standard-webhooks');

    expect($registered->secret)->toStartWith('whsec_');

    publishUserCreated($scope);

    [$request, $envelope] = $this->recordedWebhookRequests('https://receiver.test/hooks')[0];

    expect($request->header('webhook-id')[0])->toBe($envelope['delivery_id']);

    $this->fakeWebhookEndpoint('inbound', 'standard-webhooks', $registered->secret);
    $verified = app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()));

    expect($verified->eventId)->toBe($envelope['delivery_id']);
});

it('treats a repeated idempotency key in the same scope as a duplicate and a different scope as new', function (): void {
    $this->registerWebhookEndpoint(Scope::of('org_1'), 'https://receiver.test/hooks', ['user.created']);
    $this->registerWebhookEndpoint(Scope::of('org_2'), 'https://other.test/hooks', ['user.created']);

    $first = publishUserCreated(Scope::of('org_1'), 'user:1:created');
    $again = publishUserCreated(Scope::of('org_1'), 'user:1:created');
    $elsewhere = publishUserCreated(Scope::of('org_2'), 'user:1:created');

    expect($again->duplicate)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and($again->deliveries)->toBe(0)
        ->and($elsewhere->duplicate)->toBeFalse()
        ->and(WebhookEvent::query()->count())->toBe(2);

    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
});

it('refuses an unknown type when the catalog is strict and logs it otherwise', function (): void {
    $scope = Scope::of('org_1');

    expect(fn () => app(Publisher::class)->publish(new OutboundEvent('nope.happened', $scope, [])))
        ->toThrow(UnknownEventType::class);

    config(['webhooks.catalog.strict' => false]);

    $published = app(Publisher::class)->publish(new OutboundEvent('nope.happened', $scope, []));

    expect($published->deliveries)->toBe(0)->and(WebhookEvent::query()->count())->toBe(1);
});

it('refuses a payload over the configured size', function (): void {
    config(['webhooks.max_payload_bytes' => 64]);

    expect(fn () => publishUserCreated(Scope::of('org_1'), null, ['blob' => str_repeat('x', 100)]))
        ->toThrow(PayloadTooLarge::class);
});

it('records pending deliveries for a paused endpoint and none for a disabled one', function (): void {
    $scope = Scope::of('org_1');
    $paused = $this->registerWebhookEndpoint($scope, 'https://receiver.test/paused', ['user.created']);
    $disabled = $this->registerWebhookEndpoint($scope, 'https://receiver.test/disabled', ['user.created']);

    Webhooks::endpoints()->pause($scope, $paused->endpoint->id);
    WebhookEndpoint::query()->whereKey($disabled->endpoint->id)->update(['status' => 'disabled']);

    $published = publishUserCreated($scope);

    expect($published->deliveries)->toBe(1)
        ->and(WebhookDelivery::query()->count())->toBe(1)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Pending)
        ->and(WebhookDelivery::query()->firstOrFail()->endpoint_id)->toBe($paused->endpoint->id);
    $this->assertWebhookNotDelivered('https://receiver.test/paused');
    $this->assertWebhookNotDelivered('https://receiver.test/disabled');

    Webhooks::endpoints()->resume($scope, $paused->endpoint->id);

    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Delivered);
    $this->assertWebhookDeliveredTimes('https://receiver.test/paused', 1);
});

it('keeps a paused endpoint backlog in order and replays it on resume', function (): void {
    $scope = Scope::of('org_1');
    $registered = $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);
    Webhooks::endpoints()->pause($scope, $registered->endpoint->id);

    foreach ([1, 2, 3] as $n) {
        publishUserCreated($scope, null, ['n' => $n]);
    }

    // A delivery claimed by a worker after the pause is parked with its attempt handed back, not dropped.
    $first = WebhookDelivery::query()->orderBy('sequence')->firstOrFail();
    $result = app(Deliverer::class)->deliver($first->id, 0);

    expect($result?->outcome)->toBe(AttemptOutcome::Deferred)
        ->and($first->refresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($first->attempt)->toBe(0);
    $this->assertWebhookNotDelivered('https://receiver.test/hooks');

    Webhooks::endpoints()->resume($scope, $registered->endpoint->id);

    expect(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(3);

    $sequences = array_map(static fn (array $pair): int => $pair[1]['sequence'], $this->recordedWebhookRequests('https://receiver.test/hooks'));
    expect($sequences)->toBe([1, 2, 3]);
});

it('fires the publish and delivery events with value objects', function (): void {
    Event::fake([EventPublished::class, DeliveryQueued::class, DeliverySucceeded::class]);
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    publishUserCreated($scope);

    Event::assertDispatched(EventPublished::class, fn (EventPublished $e): bool => $e->published->deliveries === 1);
    Event::assertDispatched(DeliveryQueued::class);
    Event::assertDispatched(DeliverySucceeded::class, fn (DeliverySucceeded $e): bool => $e->attempt->responseCode === 200 && $e->delivery->status === DeliveryStatus::Delivered);
});

it('publishes many events in one transaction', function (): void {
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.*']);

    $results = Webhooks::publishMany([
        new OutboundEvent('user.created', $scope, ['id' => 1]),
        new OutboundEvent('user.deleted', $scope, ['id' => 1]),
    ]);

    expect($results)->toHaveCount(2);
    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.created');
    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.deleted', fn ($r, array $envelope): bool => $envelope['sequence'] === 2);
});

it('sends the endpoint static headers and the api version', function (): void {
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created'], headers: ['Authorization' => 'Bearer t0k'], apiVersion: '2026-10-01');

    publishUserCreated($scope);

    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.created', fn (Request $request, array $envelope): bool => $request->hasHeader('Authorization', 'Bearer t0k') && $envelope['api_version'] === '2026-10-01');
});

it('refuses to send anywhere while Http is faked without a matching stub', function (): void {
    Http::fake(['receiver.test/*' => Http::response('ok', 200)]);
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    publishUserCreated($scope);

    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
});
