<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Http\Routes;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Tests\Fixtures\RequestScopeResolver;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Routing\Router;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    Routes::register(app(Router::class), ['prefix' => 'api/webhooks']);
    app()->bind(ScopeResolver::class, RequestScopeResolver::class);
});

it('takes the delivery policy, the raw envelope and a transform through the API', function (): void {
    $this->fakePayloadTransformer();

    $created = $this->postJson('/api/webhooks/endpoints', [
        'url' => 'https://receiver.test/hooks',
        'event_types' => ['user.created'],
        'envelope' => 'raw',
        'retry_schedule' => [10, 60],
        'timeout_seconds' => 5,
        'follow_redirects' => true,
        'transform' => 'data',
    ])->assertCreated()
        ->assertJsonPath('data.envelope', 'raw')
        ->assertJsonPath('data.retry_schedule', [10, 60])
        ->assertJsonPath('data.timeout_seconds', 5)
        ->assertJsonPath('data.follow_redirects', true)
        ->assertJsonPath('data.transform', 'data');

    $id = $created->json('data.id');

    $this->patchJson("/api/webhooks/endpoints/{$id}", ['retry_schedule' => null, 'timeout_seconds' => null, 'transform' => null, 'follow_redirects' => false])
        ->assertOk()
        ->assertJsonPath('data.retry_schedule', null)
        ->assertJsonPath('data.timeout_seconds', null)
        ->assertJsonPath('data.transform', null)
        ->assertJsonPath('data.follow_redirects', false);

    $this->patchJson("/api/webhooks/endpoints/{$id}", ['retry_schedule' => []])->assertOk()->assertJsonPath('data.retry_schedule', []);
});

it('refuses an out-of-bounds policy and bad headers through the API', function (array $body, string $field): void {
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/hooks', 'event_types' => ['user.created'], ...$body])
        ->assertStatus(422)->assertJsonValidationErrors([$field]);
})->with([
    'timeout' => [['timeout_seconds' => 121], 'timeout_seconds'],
    'schedule entry' => [['retry_schedule' => [0]], 'retry_schedule.0'],
    'reserved prefix' => [['headers' => ['X-Cbox-Org' => 'x']], 'headers'],
    'line break' => [['headers' => ['X-A' => "a\nb"]], 'headers'],
]);

it('replays named events, the undelivered only, paced, and answers a dry run with the count', function (): void {
    $scope = Scope::of('org_1', 'env_1');
    $registered = $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 1]));
    $eventId = WebhookEvent::query()->value('id');
    $id = $registered->endpoint->id;
    $headers = ['X-Org' => 'org_1', 'X-Partition' => 'env_1'];

    $this->postJson("/api/webhooks/endpoints/{$id}/replay", ['since' => '2026-10-30T00:00:00Z', 'only_undelivered' => true, 'dry_run' => true], $headers)
        ->assertOk()->assertJsonPath('data.would_replay', 0)->assertJsonPath('data.replayed', 0);

    $this->postJson("/api/webhooks/endpoints/{$id}/replay", ['event_ids' => [$eventId], 'dry_run' => true], $headers)
        ->assertOk()->assertJsonPath('data.would_replay', 1);

    expect(WebhookDelivery::query()->count())->toBe(1);

    $this->postJson("/api/webhooks/endpoints/{$id}/replay", ['event_ids' => [$eventId], 'per_minute' => 60], $headers)
        ->assertOk()->assertJsonPath('data.replayed', 1);

    expect(WebhookDelivery::query()->count())->toBe(2);
});

it('replays and dry-runs named events from the console', function (): void {
    $scope = Scope::of('org_1', 'env_1');
    $registered = $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 1]));
    $eventId = WebhookEvent::query()->value('id');
    $id = $registered->endpoint->id;

    $this->artisan("webhooks:replay {$id} --event={$eventId} --dry-run")->expectsOutputToContain('1 deliveries would be re-created')->assertSuccessful();
    $this->artisan("webhooks:replay {$id} --since=\"2026-10-30 00:00:00\" --undelivered")->expectsOutputToContain('0 deliveries re-created')->assertSuccessful();
    $this->artisan("webhooks:replay {$id} --event={$eventId} --per-minute=10")->expectsOutputToContain('1 deliveries re-created')->assertSuccessful();
});
