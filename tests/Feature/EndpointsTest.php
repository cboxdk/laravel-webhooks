<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Events\SecretRotated;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\InvalidScope;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Exceptions\WideEndpointsNotAllowed;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1', 'env_1');
    $this->other = Scope::of('org_2', 'env_1');
});

it('registers with a shown-once secret and never exposes it on the endpoint', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.*', 'invoice.paid']);

    expect($registered->secret)->toHaveLength(64)
        ->and($registered->endpoint->scope->equals($this->scope))->toBeTrue()
        ->and($registered->endpoint->eventTypes)->toBe(['user.*', 'invoice.paid'])
        ->and($registered->endpoint->status)->toBe(EndpointStatus::Active)
        ->and($registered->endpoint->toArray())->not->toHaveKey('secret')
        ->and(WebhookEndpoint::query()->firstOrFail()->secret)->toBe($registered->secret)
        ->and(WebhookEndpoint::query()->firstOrFail()->getRawOriginal('secret'))->not->toBe($registered->secret);
});

it('refuses an unsafe, a private and a non-https URL unless http is allowed', function (): void {
    $spec = fn (string $url): NewEndpoint => new NewEndpoint($url, ['user.created']);

    expect(fn () => Webhooks::endpoints()->register($this->scope, $spec('https://localhost/hooks')))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, $spec('https://10.0.0.5/hooks')))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, $spec('http://receiver.test/hooks')))->toThrow(InvalidEndpoint::class);

    config(['webhooks.allow_http' => true]);
    expect(Webhooks::endpoints()->register($this->scope, $spec('http://receiver.test/hooks'))->endpoint->url)->toBe('http://receiver.test/hooks');
});

it('refuses reserved headers, unknown schemes, bad batch and rate bounds and unknown types', function (): void {
    $base = ['url' => 'https://receiver.test/hooks', 'eventTypes' => ['user.created']];

    expect(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, headers: ['Host' => 'evil'])))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, headers: ['X-Cbox-Signature' => 'x'])))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, scheme: 'mailgun')))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, scheme: 'nope')))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, batchSize: 101)))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, batchWindowSeconds: 301)))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint(...$base, rateLimitPerMinute: 0)))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint('https://receiver.test/hooks', [])))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['nope.*'])))->toThrow(UnknownEventType::class)
        ->and(fn () => Webhooks::endpoints()->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.nope'])))->toThrow(UnknownEventType::class);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('refuses wide endpoints unless the operator allowed them, and never through register()', function (): void {
    $spec = new NewEndpoint('https://receiver.test/hooks', ['*']);

    expect(fn () => Webhooks::endpoints()->registerPartitionWide('env_1', $spec))->toThrow(WideEndpointsNotAllowed::class)
        ->and(fn () => Webhooks::endpoints()->registerGlobal($spec))->toThrow(WideEndpointsNotAllowed::class)
        ->and(fn () => Webhooks::endpoints()->register(Scope::partitionWide('env_1'), $spec))->toThrow(InvalidScope::class)
        ->and(fn () => Scope::of(''))->toThrow(InvalidScope::class);

    config(['webhooks.allow_wide_endpoints' => true]);

    $wide = Webhooks::endpoints()->registerPartitionWide('env_1', $spec);
    $global = Webhooks::endpoints()->registerGlobal(new NewEndpoint('https://other.test/hooks', ['*']));

    expect($wide->endpoint->scope->isWide())->toBeTrue()->and($global->endpoint->scope->isGlobal())->toBeTrue();

    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_7', 'env_1'), []));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_7', 'env_2'), []));

    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
    $this->assertWebhookDeliveredTimes('https://other.test/hooks', 2);
});

it('answers null for an id from another scope on every lookup and mutation', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    $id = $registered->endpoint->id;
    $endpoints = Webhooks::endpoints();

    expect($endpoints->find($this->other, $id))->toBeNull()
        ->and($endpoints->find(Scope::of('org_1'), $id))->toBeNull()
        ->and($endpoints->update($this->other, $id, new EndpointChanges(description: 'x')))->toBeNull()
        ->and($endpoints->pause($this->other, $id))->toBeNull()
        ->and($endpoints->resume($this->other, $id))->toBeNull()
        ->and($endpoints->rotateSecret($this->other, $id))->toBeNull()
        ->and($endpoints->changeScheme($this->other, $id, 'standard-webhooks'))->toBeNull()
        ->and($endpoints->ping($this->other, $id))->toBeNull()
        ->and($endpoints->health($this->other, $id))->toBeNull()
        ->and($endpoints->replay($this->other, $id, new ReplayRequest(CarbonImmutable::now()->subDay())))->toBe(0)
        ->and($endpoints->delete($this->other, $id))->toBeFalse()
        ->and($endpoints->list($this->other))->toBe([])
        ->and($endpoints->list($this->scope))->toHaveCount(1)
        ->and(WebhookEndpoint::query()->firstOrFail()->status)->toBe(EndpointStatus::Active);
});

it('updates the mutable fields and validates them like a registration', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    $this->fakeDnsForUrl('https://new.test/x');

    $updated = Webhooks::endpoints()->update($this->scope, $registered->endpoint->id, new EndpointChanges(
        url: 'https://new.test/x',
        description: 'Billing sync',
        eventTypes: ['invoice.paid'],
        batchSize: 5,
        batchWindowSeconds: 10,
        rateLimitPerMinute: 30,
        headers: ['Authorization' => 'Bearer t'],
        apiVersion: '2026-01-01',
        metadata: ['owner' => 'ops'],
    ));

    expect($updated?->url)->toBe('https://new.test/x')
        ->and($updated?->description)->toBe('Billing sync')
        ->and($updated?->eventTypes)->toBe(['invoice.paid'])
        ->and($updated?->batchSize)->toBe(5)
        ->and($updated?->rateLimitPerMinute)->toBe(30)
        ->and($updated?->headers)->toBe(['Authorization' => 'Bearer t'])
        ->and($updated?->apiVersion)->toBe('2026-01-01')
        ->and($updated?->metadata)->toBe(['owner' => 'ops']);

    $cleared = Webhooks::endpoints()->update($this->scope, $registered->endpoint->id, new EndpointChanges(clearRateLimit: true, clearApiVersion: true, clearDescription: true));
    expect($cleared?->rateLimitPerMinute)->toBeNull()->and($cleared?->apiVersion)->toBeNull()->and($cleared?->description)->toBeNull();

    expect(fn () => Webhooks::endpoints()->update($this->scope, $registered->endpoint->id, new EndpointChanges(headers: ['webhook-signature' => 'x'])))->toThrow(InvalidEndpoint::class)
        ->and(fn () => Webhooks::endpoints()->update($this->scope, $registered->endpoint->id, new EndpointChanges(eventTypes: ['nope'])))->toThrow(UnknownEventType::class)
        ->and(fn () => Webhooks::endpoints()->update($this->scope, $registered->endpoint->id, new EndpointChanges(url: 'https://10.1.1.1/')))->toThrow(InvalidEndpoint::class);
});

it('rotates the secret with an overlap: new secret signs now, old one expires through the sweep', function (): void {
    Event::fake([SecretRotated::class]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    $rotated = Webhooks::endpoints()->rotateSecret($this->scope, $registered->endpoint->id, 3600);

    expect($rotated?->secret)->not->toBe($registered->secret)
        ->and($rotated?->previousSecretExpiresAt?->toDateTimeString())->toBe('2026-10-30 13:00:00');
    Event::assertDispatched(SecretRotated::class);

    $model = WebhookEndpoint::query()->firstOrFail();
    expect($model->previous_secret)->toBe($registered->secret)->and($model->secret)->toBe($rotated?->secret);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));
    [$request] = $this->recordedWebhookRequests('https://receiver.test/hooks')[0];

    // A receiver holding both secrets verifies, and the new one is what matched.
    $this->fakeWebhookEndpoint('inbound', 'cbox', ['new' => (string) $rotated?->secret, 'old' => $registered->secret]);
    expect(app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()))->secretId)->toBe('new');

    CarbonImmutable::setTestNow('2026-10-30 13:00:00');
    expect($this->deliverPendingWebhooks()->secretsExpired)->toBe(1)
        ->and(WebhookEndpoint::query()->firstOrFail()->previous_secret)->toBeNull();
});

it('changes the scheme with a fresh secret in the new form and no overlap', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    Webhooks::endpoints()->rotateSecret($this->scope, $registered->endpoint->id);

    $changed = Webhooks::endpoints()->changeScheme($this->scope, $registered->endpoint->id, 'standard-webhooks');

    expect($changed?->endpoint->scheme)->toBe('standard-webhooks')
        ->and($changed?->secret)->toStartWith('whsec_')
        ->and(WebhookEndpoint::query()->firstOrFail()->previous_secret)->toBeNull();

    expect(fn () => Webhooks::endpoints()->changeScheme($this->scope, $registered->endpoint->id, 'nope'))->toThrow(InvalidEndpoint::class);
});

it('deletes an endpoint, cancels its backlog and keeps history', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    expect(Webhooks::endpoints()->delete($this->scope, $registered->endpoint->id))->toBeTrue()
        ->and(WebhookEndpoint::query()->count())->toBe(0)
        ->and(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Cancelled)
        ->and(WebhookAttempt::query()->count())->toBe(1);
});

it('pings synchronously and records the ping as a delivery and an attempt', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    $result = Webhooks::endpoints()->ping($this->scope, $registered->endpoint->id);

    expect($result?->succeeded())->toBeTrue()
        ->and($result?->responseCode)->toBe(200)
        ->and(WebhookDelivery::query()->firstOrFail()->type)->toBe('webhook.ping')
        ->and(WebhookDelivery::query()->firstOrFail()->sequence)->toBe(1);
    $this->assertWebhookDelivered('https://receiver.test/hooks', 'webhook.ping');

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));
    expect(WebhookDelivery::query()->orderByDesc('sequence')->firstOrFail()->sequence)->toBe(2);
});

it('replays stored events since a time, filtered by type, with fresh sequences', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.*']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['n' => 1], null, CarbonImmutable::parse('2026-10-29 09:00:00')));
    app(Publisher::class)->publish(new OutboundEvent('user.deleted', $this->scope, ['n' => 2], null, CarbonImmutable::parse('2026-10-29 10:00:00')));
    app(Publisher::class)->publish(new OutboundEvent('invoice.paid', $this->scope, ['n' => 3], null, CarbonImmutable::parse('2026-10-29 11:00:00')));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->other, ['n' => 4], null, CarbonImmutable::parse('2026-10-29 11:00:00')));

    $count = Webhooks::endpoints()->replay($this->scope, $registered->endpoint->id, new ReplayRequest(
        since: CarbonImmutable::parse('2026-10-29 09:30:00'),
    ));

    expect($count)->toBe(1); // user.deleted only: invoice.paid is not subscribed, org_2 is another scope
    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.deleted', fn ($r, array $e): bool => $e['sequence'] === 3 && $e['data'] === ['n' => 2]);

    $count = Webhooks::endpoints()->replay($this->scope, $registered->endpoint->id, new ReplayRequest(
        since: CarbonImmutable::parse('2026-10-29 00:00:00'),
        until: CarbonImmutable::parse('2026-10-29 09:30:00'),
        types: ['user.created'],
    ));
    expect($count)->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 4);
});

it('matches wildcard subscriptions against types registered after the endpoint', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.*']);
    $this->defineWebhookEvent('user.promoted', 'user');

    app(Publisher::class)->publish(new OutboundEvent('user.promoted', $this->scope, []));

    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.promoted');
});

it('does not offer a legacy type through a wildcard but still delivers it to an explicit subscription', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/wild', ['user.*']);
    $this->registerWebhookEndpoint($this->scope, 'https://other.test/explicit', ['user.renamed']);

    app(Publisher::class)->publish(new OutboundEvent('user.renamed', $this->scope, []));

    $this->assertWebhookNotDelivered('https://receiver.test/wild');
    $this->assertWebhookDelivered('https://other.test/explicit', 'user.renamed');
});

it('reports health as a typed snapshot', function (): void {
    Http::fake(['receiver.test/*' => Http::response('oops', 500)]);
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    $health = Webhooks::endpoints()->health($this->scope, $registered->endpoint->id);

    expect($health?->consecutiveFailures)->toBe(1)
        ->and($health?->lastResponseCode)->toBe(500)
        ->and($health?->lastError)->toBe('HTTP 500')
        ->and($health?->isTripped())->toBeFalse()
        ->and($health?->toArray())->toHaveKeys(['tripped', 'consecutive_failures', 'circuit_closes_at', 'first_failure_at']);
});
