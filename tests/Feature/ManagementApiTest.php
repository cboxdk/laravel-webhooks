<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Exceptions\ScopeResolverMissing;
use Cbox\Webhooks\Http\Routes;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Tests\Fixtures\RequestScopeResolver;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    Routes::register(app(Router::class), ['prefix' => 'api/webhooks']);
    app()->bind(ScopeResolver::class, RequestScopeResolver::class);
    $this->withoutExceptionHandling();
});

it('round-trips an endpoint through the API', function (): void {
    $created = $this->postJson('/api/webhooks/endpoints', [
        'url' => 'https://receiver.test/hooks',
        'event_types' => ['user.*'],
        'description' => 'CRM sync',
        'headers' => ['Authorization' => 'Bearer t'],
        'rate_limit_per_minute' => 60,
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.url', 'https://receiver.test/hooks')
        ->assertJsonPath('data.scope.key', 'org_1')
        ->assertJsonPath('data.status', 'active')
        // Sealed at rest and masked on the way out: the API never echoes a header's secret.
        ->assertJsonPath('data.headers.Authorization', 'Be…');
    $id = $created->json('data.id');
    $secret = $created->json('data.secret');
    expect($secret)->toHaveLength(64);

    $this->getJson('/api/webhooks/endpoints')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    $this->getJson("/api/webhooks/endpoints/{$id}")->assertOk()->assertJsonPath('data.id', $id);

    $this->patchJson("/api/webhooks/endpoints/{$id}", ['description' => 'Renamed', 'rate_limit_per_minute' => null, 'event_types' => ['invoice.paid']])
        ->assertOk()
        ->assertJsonPath('data.description', 'Renamed')
        ->assertJsonPath('data.rate_limit_per_minute', null)
        ->assertJsonPath('data.event_types', ['invoice.paid']);

    $this->postJson("/api/webhooks/endpoints/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
    $this->postJson("/api/webhooks/endpoints/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'active');

    $rotated = $this->postJson("/api/webhooks/endpoints/{$id}/rotate-secret", ['overlap_seconds' => 60]);
    $rotated->assertOk()->assertJsonPath('data.previous_secret_expires_at', '2026-10-30T12:01:00+00:00');
    expect($rotated->json('data.secret'))->not->toBe($secret);

    $this->postJson("/api/webhooks/endpoints/{$id}/ping")->assertOk()->assertJsonPath('data.outcome', 'success')->assertJsonPath('data.response_code', 200);

    app(Publisher::class)->publish(new OutboundEvent('invoice.paid', Scope::of('org_1'), ['n' => 1]));

    $this->getJson("/api/webhooks/endpoints/{$id}/deliveries?status=delivered")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/webhooks/endpoints/{$id}/deliveries?status=bogus")->assertStatus(422)->assertJsonPath('error', 'validation');
    $this->getJson("/api/webhooks/endpoints/{$id}/stats?hours=1")->assertOk()->assertJsonPath('data.delivered', 2);

    $this->postJson("/api/webhooks/endpoints/{$id}/replay", ['since' => '2026-10-30T00:00:00Z', 'types' => ['invoice.paid']])
        ->assertOk()->assertJsonPath('data.replayed', 1);

    $deliveryId = WebhookDelivery::query()->where('type', 'invoice.paid')->firstOrFail()->id;
    $this->getJson("/api/webhooks/deliveries/{$deliveryId}")->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonCount(1, 'data.attempts');
    $this->postJson("/api/webhooks/deliveries/{$deliveryId}/retry")->assertStatus(422);

    $this->getJson('/api/webhooks/catalog')->assertOk()->assertJsonPath('data.0.type', 'user.created');

    $this->deleteJson("/api/webhooks/endpoints/{$id}")->assertNoContent();
    $this->getJson("/api/webhooks/endpoints/{$id}")->assertNotFound();
});

it('retries a failed delivery through the API', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)]);
    $this->registerWebhookEndpoint(Scope::of('org_1'), 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_1'), []));
    $deliveryId = WebhookDelivery::query()->firstOrFail()->id;

    $this->postJson("/api/webhooks/deliveries/{$deliveryId}/retry")->assertStatus(202)->assertJsonPath('data.queued', true);

    expect(WebhookDelivery::query()->firstOrFail()->status->value)->toBe('delivered');
});

it('answers 404 for another scope and 422 for invalid input', function (): void {
    $registered = $this->registerWebhookEndpoint(Scope::of('org_1'), 'https://receiver.test/hooks', ['user.created']);
    $id = $registered->endpoint->id;

    $this->getJson("/api/webhooks/endpoints/{$id}", ['X-Org' => 'org_2'])->assertNotFound();
    $this->patchJson("/api/webhooks/endpoints/{$id}", ['description' => 'x'], ['X-Org' => 'org_2'])->assertNotFound();
    $this->postJson("/api/webhooks/endpoints/{$id}/ping", [], ['X-Org' => 'org_2'])->assertNotFound();
    $this->getJson("/api/webhooks/endpoints/{$id}/deliveries", ['X-Org' => 'org_2'])->assertNotFound();
    $this->deleteJson("/api/webhooks/endpoints/{$id}", [], ['X-Org' => 'org_2'])->assertNotFound();

    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://10.0.0.1/x', 'event_types' => ['user.created']])
        ->assertStatus(422)->assertJsonPath('error', 'validation')->assertJsonValidationErrors(['url']);
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/x', 'event_types' => ['nope.*']])
        ->assertStatus(422)->assertJsonValidationErrors(['event_types']);
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/x', 'event_types' => ['user.created'], 'headers' => ['Host' => 'x']])
        ->assertStatus(422)->assertJsonValidationErrors(['headers']);
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/x', 'event_types' => ['user.created'], 'batch_size' => 500])
        ->assertStatus(422)->assertJsonValidationErrors(['batch_size']);
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/x', 'event_types' => ['user.created'], 'scheme' => 'github'])
        ->assertStatus(422)->assertJsonValidationErrors(['scheme']);
    $this->postJson("/api/webhooks/endpoints/{$id}/replay", [])->assertStatus(422)->assertJsonValidationErrors(['since']);
});

it('refuses to serve without a scope resolver', function (): void {
    app()->offsetUnset(ScopeResolver::class);
    app()->forgetInstance(ScopeResolver::class);

    expect(fn () => $this->getJson('/api/webhooks/endpoints'))->toThrow(ScopeResolverMissing::class);
});
