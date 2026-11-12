<?php

declare(strict_types=1);

use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Catalog\SharedEventCatalogs;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Http\Routes;
use Cbox\Webhooks\Tests\Fixtures\RequestScopeResolver;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Routing\Router;

beforeEach(function (): void {
    $this->shop = Scope::of('shop', 'org_a');
    $this->desk = Scope::of('desk', 'org_b');

    $this->catalogs = $this->fakeEventCatalogs()
        ->define($this->shop, new EventDefinition('order.shipped', 'order', 'Order shipped', 'An order left the warehouse.'), new EventDefinition('order.refunded', 'order', 'Order refunded', 'An order was refunded.'))
        ->define($this->desk, new EventDefinition('ticket.opened', 'ticket', 'Ticket opened', 'A ticket was opened.'));
});

it('answers the shared catalog for every scope by default', function (): void {
    app()->forgetInstance(EventCatalogs::class);

    expect(app(EventCatalogs::class))->toBeInstanceOf(SharedEventCatalogs::class)
        ->and(app(EventCatalogs::class)->for($this->shop))->toBe(app(EventCatalog::class))
        ->and(Webhooks::catalog($this->shop))->toBe(Webhooks::catalog())
        ->and(Webhooks::catalog()->has('user.created'))->toBeTrue();
});

it('publishes a type its own scope offers and refuses one only another scope offers', function (): void {
    $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['order.*']);

    $published = app(Publisher::class)->publish(new OutboundEvent('order.shipped', $this->shop, ['id' => 'ord_1']));

    expect($published->deliveries)->toBe(1);
    $this->assertWebhookDelivered('https://receiver.test/shop', 'order.shipped');

    // The other customer's type, a type of the shared catalog, and a scope nobody defined.
    expect(fn () => app(Publisher::class)->publish(new OutboundEvent('ticket.opened', $this->shop, [])))->toThrow(UnknownEventType::class)
        ->and(fn () => app(Publisher::class)->publish(new OutboundEvent('order.shipped', $this->desk, [])))->toThrow(UnknownEventType::class)
        ->and(fn () => app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, [])))->toThrow(UnknownEventType::class)
        ->and(fn () => app(Publisher::class)->publish(new OutboundEvent('order.shipped', Scope::of('nobody', 'org_c'), [])))->toThrow(UnknownEventType::class);
});

it('validates a subscription against the endpoint\'s own scope on register and update', function (): void {
    $endpoints = app(Endpoints::class);
    $this->fakeDnsForUrl('https://receiver.test/desk');

    expect(fn () => $endpoints->register($this->desk, new NewEndpoint('https://receiver.test/desk', ['order.shipped'])))->toThrow(UnknownEventType::class)
        ->and(fn () => $endpoints->register($this->desk, new NewEndpoint('https://receiver.test/desk', ['order.*'])))->toThrow(UnknownEventType::class);

    $registered = $endpoints->register($this->desk, new NewEndpoint('https://receiver.test/desk', ['ticket.*']));

    expect(fn () => $endpoints->update($this->desk, $registered->endpoint->id, new EndpointChanges(eventTypes: ['order.shipped'])))->toThrow(UnknownEventType::class)
        ->and($endpoints->update($this->desk, $registered->endpoint->id, new EndpointChanges(eventTypes: ['*']))?->eventTypes)->toBe(['*']);
});

it('expands wildcards per scope on fan-out and replay', function (): void {
    $shop = $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['*']);
    $this->registerWebhookEndpoint($this->desk, 'https://receiver.test/desk', ['*']);

    app(Publisher::class)->publish(new OutboundEvent('order.shipped', $this->shop, ['id' => 'ord_1']));
    app(Publisher::class)->publish(new OutboundEvent('ticket.opened', $this->desk, ['id' => 'tic_1']));

    $this->assertWebhookDeliveredTimes('https://receiver.test/shop', 1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/desk', 1);
    $this->assertWebhookDelivered('https://receiver.test/desk', 'ticket.opened');

    // A type the scope starts offering later is covered by the wildcard at match time.
    $this->catalogs->define($this->shop, new EventDefinition('order.cancelled', 'order', 'Order cancelled', 'An order was cancelled.'));
    app(Publisher::class)->publish(new OutboundEvent('order.cancelled', $this->shop, ['id' => 'ord_2']));
    $this->assertWebhookDelivered('https://receiver.test/shop', 'order.cancelled');

    expect(app(Endpoints::class)->replay($this->shop, $shop->endpoint->id, new ReplayRequest(since: now()->toImmutable()->subHour())))->toBe(2);
});

it('serves each caller its own catalog through the API and validates subscriptions against it', function (): void {
    Routes::register(app(Router::class), ['prefix' => 'api/webhooks']);
    app()->bind(ScopeResolver::class, RequestScopeResolver::class);

    $asDesk = ['X-Org' => 'desk', 'X-Partition' => 'org_b'];

    $this->getJson('/api/webhooks/catalog', $asDesk)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'ticket.opened');
    $this->getJson('/api/webhooks/catalog', ['X-Org' => 'shop', 'X-Partition' => 'org_a'])->assertOk()->assertJsonCount(2, 'data');

    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/desk', 'event_types' => ['order.shipped']], $asDesk)
        ->assertStatus(422)->assertJsonPath('error', 'validation');

    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/desk', 'event_types' => ['ticket.opened']], $asDesk)
        ->assertCreated();
});

it('prints one scope\'s catalog from the console', function (): void {
    $this->artisan('webhooks:catalog --scope=org_b/desk')->expectsOutputToContain('ticket.opened')->doesntExpectOutputToContain('order.shipped')->assertSuccessful();
    $this->artisan('webhooks:catalog --scope=org_a/shop --markdown')->expectsOutputToContain('`order.shipped`')->assertSuccessful();
    $this->artisan('webhooks:catalog --scope=org_c/nobody')->expectsOutputToContain('The catalog is empty')->assertSuccessful();
});
