<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Erasure;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->shop = Scope::of('shop', 'org_a');
    $this->desk = Scope::of('desk', 'org_b');
    $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['user.created']);
    $this->registerWebhookEndpoint($this->desk, 'https://receiver.test/desk', ['user.created'], batchSize: 2);
});

it('writes the delivery scope on every attempt, single and batched', function (): void {
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['id' => 2]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['id' => 3]));

    expect(WebhookAttempt::query()->where('partition', 'org_a')->where('scope_key', 'shop')->count())->toBe(1)
        ->and(WebhookAttempt::query()->where('partition', 'org_b')->where('scope_key', 'desk')->count())->toBe(2)
        ->and(WebhookAttempt::query()->whereNull('scope_key')->count())->toBe(0)
        ->and(WebhookAttempt::query()->where('scope_key', 'shop')->firstOrFail()->scope()->equals($this->shop))->toBeTrue();

    $delivery = WebhookDelivery::query()->where('scope_key', 'shop')->firstOrFail();
    $found = app(Deliveries::class)->find($this->shop, $delivery->id);

    expect($found?->attempts[0]->scope->equals($this->shop))->toBeTrue()
        ->and($found?->attempts[0]->toArray()['scope'])->toBe(['partition' => 'org_a', 'key' => 'shop']);
});

it('records the scope on a rate-limited attempt that never reached the transport', function (): void {
    $limited = Scope::of('slow', 'org_c');
    $this->registerWebhookEndpoint($limited, 'https://receiver.test/slow', ['user.created'], rateLimitPerMinute: 1);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $limited, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $limited, ['id' => 2]));

    expect(WebhookAttempt::query()->where('partition', 'org_c')->where('scope_key', 'slow')->count())->toBe(2);
});

it('backfills existing attempts from their delivery when the migration runs', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('shop'), ['id' => 2]));
    $this->registerWebhookEndpoint(Scope::of('solo'), 'https://receiver.test/solo', ['user.created']);
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('solo'), ['id' => 3]));

    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000000_add_scope_to_webhook_attempts.php';

    // Back to the 0.1 table, with its rows, and forward again.
    $migration->down();
    expect(Schema::hasColumn('webhook_attempts', 'scope_key'))->toBeFalse()
        ->and(WebhookAttempt::query()->count())->toBe(2);

    $migration->up();

    expect(WebhookAttempt::query()->where('partition', 'org_a')->where('scope_key', 'shop')->count())->toBe(1)
        ->and(WebhookAttempt::query()->whereNull('partition')->where('scope_key', 'solo')->count())->toBe(1);
});

it('erases one scope\'s attempts and leaves the other\'s', function (): void {
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['id' => 2]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['id' => 3]));

    // An attempt whose delivery is already gone is found by its own scope columns.
    WebhookDelivery::query()->where('scope_key', 'shop')->delete();

    expect(app(Erasure::class)->forget($this->shop))->toBe(2)
        ->and(WebhookAttempt::query()->where('scope_key', 'shop')->count())->toBe(0)
        ->and(WebhookAttempt::query()->where('scope_key', 'desk')->count())->toBe(2);
});
