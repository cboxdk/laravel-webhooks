<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\SubjectErasure;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->shop = Scope::of('shop', 'org_a');
    $this->desk = Scope::of('desk', 'org_b');
    $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['user.created']);
    $this->registerWebhookEndpoint($this->desk, 'https://receiver.test/desk', ['user.created']);
});

it('stores the subject with the event and never sends it', function (): void {
    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1], subject: 'contact_1'));

    expect(WebhookEvent::query()->findOrFail($published->id)->subject)->toBe('contact_1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://receiver.test/shop' && ! str_contains((string) $request->body(), 'contact_1'));
    Http::assertSentCount(1);
});

it('removes one person\'s events, deliveries and attempts and nothing else', function (): void {
    $publisher = app(Publisher::class);
    $publisher->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1], subject: 'contact_1'));
    $publisher->publish(new OutboundEvent('user.created', $this->shop, ['id' => 2], subject: 'contact_1'));
    $publisher->publish(new OutboundEvent('user.created', $this->shop, ['id' => 3], subject: 'contact_2'));
    $publisher->publish(new OutboundEvent('user.created', $this->shop, ['id' => 4]));
    // The same id in another scope is another person.
    $publisher->publish(new OutboundEvent('user.created', $this->desk, ['id' => 5], subject: 'contact_1'));

    // Two events, two deliveries, two attempts.
    expect(app(SubjectErasure::class)->forgetSubject($this->shop, 'contact_1'))->toBe(6)
        ->and(WebhookEvent::query()->where('subject', 'contact_1')->where('scope_key', 'shop')->count())->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(3)
        ->and(WebhookDelivery::query()->count())->toBe(3)
        ->and(WebhookAttempt::query()->count())->toBe(3)
        ->and(WebhookEvent::query()->where('scope_key', 'desk')->where('subject', 'contact_1')->count())->toBe(1);
});

it('removes nothing for an unknown or empty subject', function (): void {
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1]));

    expect(app(SubjectErasure::class)->forgetSubject($this->shop, 'nobody'))->toBe(0)
        ->and(app(SubjectErasure::class)->forgetSubject($this->shop, ''))->toBe(0)
        ->and(WebhookEvent::query()->count())->toBe(1);
});

it('migrates down to the 0.2.0 table and up again', function (): void {
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['id' => 1], subject: 'contact_1'));

    $migration = require __DIR__.'/../../database/migrations/2026_11_12_000002_add_subject_to_webhook_events.php';

    $migration->down();
    expect(Schema::hasColumn('webhook_events', 'subject'))->toBeFalse()
        ->and(WebhookEvent::query()->count())->toBe(1);

    $migration->up();
    expect(Schema::hasColumn('webhook_events', 'subject'))->toBeTrue();
});
