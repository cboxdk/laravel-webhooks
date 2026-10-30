<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Testing\FakeWebhooks;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use PHPUnit\Framework\AssertionFailedError;

it('swaps the publisher and asserts on what was published', function (): void {
    $fake = Webhooks::fake();

    expect(app(Publisher::class))->toBe($fake);

    $first = Webhooks::publish(new OutboundEvent('user.created', Scope::of('org_1'), ['id' => 1], 'k'));
    $again = Webhooks::publish(new OutboundEvent('user.created', Scope::of('org_1'), ['id' => 1], 'k'));
    Webhooks::publishMany([new OutboundEvent('invoice.paid', Scope::of('org_1'), ['n' => 9])]);

    expect($first->duplicate)->toBeFalse()
        ->and($again->duplicate)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and(WebhookEvent::query()->count())->toBe(0)
        ->and($fake->published())->toHaveCount(2);

    $fake->assertPublished('user.created', fn (OutboundEvent $e): bool => $e->payload === ['id' => 1]);
    $fake->assertPublished('invoice.paid');
    $fake->assertNotPublished('user.deleted');
    $fake->assertPublishedCount(1, 'user.created');

    expect(fn () => $fake->assertNotPublished('user.created'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingPublished())->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPublished('user.created', fn (): bool => false))->toThrow(AssertionFailedError::class)
        ->and(fn () => (new FakeWebhooks)->assertPublished('x'))->toThrow(AssertionFailedError::class);

    (new FakeWebhooks)->assertNothingPublished();
    $this->fakeWebhooks()->assertNothingPublished();
});

it('keeps the other facade accessors real', function (): void {
    Webhooks::fake();

    expect(Webhooks::endpoints())->toBeInstanceOf(Endpoints::class)
        ->and(Webhooks::deliveries())->toBeInstanceOf(Deliveries::class)
        ->and(Webhooks::catalog()->has('user.created'))->toBeTrue()
        ->and(Webhooks::monitor()->overview()->pending)->toBe(0)
        ->and(Webhooks::receivers())->toBeInstanceOf(Receivers::class);
});
