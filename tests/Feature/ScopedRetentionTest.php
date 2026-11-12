<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Pruner;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\Housekeeping\ConfigRetentionPolicies;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-01 12:00:00');
    $this->shop = Scope::of('shop', 'org_a');
    $this->desk = Scope::of('desk', 'org_b');
    $this->registerWebhookEndpoint($this->shop, 'https://receiver.test/shop', ['user.created']);
    $this->registerWebhookEndpoint($this->desk, 'https://receiver.test/desk', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->shop, ['n' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['n' => 2]));
});

function rowsOf(Scope $scope): array
{
    $where = fn ($q) => $q->where('partition', $scope->partition)->where('scope_key', $scope->key);

    return [$where(WebhookDelivery::query())->count(), $where(WebhookAttempt::query())->count(), $where(WebhookEvent::query())->count()];
}

it('uses the configured retention for every scope by default', function (): void {
    expect(app(RetentionPolicies::class))->toBeInstanceOf(ConfigRetentionPolicies::class)
        ->and(app(RetentionPolicies::class)->for($this->shop)->toArray())->toBe(['delivered_days' => 30, 'failed_days' => 90, 'events_days' => 30]);

    CarbonImmutable::setTestNow('2026-10-11 12:00:00');
    expect(app(Pruner::class)->prune()->deliveries)->toBe(0);

    config(['webhooks.retention.delivered_days' => 7, 'webhooks.retention.events_days' => 7]);
    $report = app(Pruner::class)->prune();

    expect($report->deliveries)->toBe(2)->and($report->attempts)->toBe(2)->and($report->events)->toBe(2);
});

it('prunes each scope by the retention its host gives it and falls back to the config', function (): void {
    $this->fakeRetentionPolicies()->set($this->shop, Retention::days(7));

    // Ten days on: past the shop's seven, inside the desk's configured thirty.
    CarbonImmutable::setTestNow('2026-10-11 12:00:00');
    $report = app(Pruner::class)->prune();

    expect($report->toArray())->toBe(['deliveries' => 1, 'attempts' => 1, 'events' => 1, 'inbound' => 0])
        ->and(rowsOf($this->shop))->toBe([0, 0, 0])
        ->and(rowsOf($this->desk))->toBe([1, 1, 1]);

    // Forty days on the configured default takes the desk's as well.
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');

    expect(app(Pruner::class)->prune()->deliveries)->toBe(1)
        ->and(rowsOf($this->desk))->toBe([0, 0, 0]);
});

it('lets a scope keep its history longer than the default, field by field', function (): void {
    Http::fake(['receiver.test/desk' => Http::response('', 500)]);
    config(['webhooks.retry.schedule' => []]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->desk, ['n' => 3]));

    // The shop keeps delivered rows a year; events and failed rows stay on the default.
    $this->fakeRetentionPolicies()
        ->set($this->shop, new Retention(deliveredDays: 365))
        ->set($this->desk, new Retention(failedDays: 20, eventsDays: 400));

    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
    $report = app(Pruner::class)->prune();

    // Shop: delivery kept (365), event gone (default 30). Desk: delivered gone (default 30),
    // exhausted gone (20), events kept (400).
    expect(rowsOf($this->shop))->toBe([1, 1, 0])
        ->and(rowsOf($this->desk))->toBe([0, 0, 2])
        ->and($report->deliveries)->toBe(2)
        ->and($report->events)->toBe(1);
});

it('asks for scopes that only have events left, and for the wide ones', function (): void {
    config()->set('webhooks.allow_wide_endpoints', true);
    WebhookDelivery::query()->delete();
    WebhookAttempt::query()->delete();
    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('solo'), ['n' => 4]));

    $asked = [];
    app()->instance(RetentionPolicies::class, new class($asked) implements RetentionPolicies
    {
        /** @param list<string> $asked */
        public function __construct(private array &$asked) {}

        public function for(Scope $scope): Retention
        {
            $this->asked[] = $scope->toString();

            return Retention::days(1);
        }
    });

    CarbonImmutable::setTestNow('2026-10-05 12:00:00');
    $report = app(Pruner::class)->prune();
    sort($asked);

    expect($asked)->toBe(['*/solo', 'org_a/shop', 'org_b/desk'])
        ->and($report->events)->toBe(3)
        ->and(WebhookEvent::query()->count())->toBe(0)
        ->and((new Retention(0, -5, 0))->orElse(new Retention)->toArray())->toBe(['delivered_days' => 1, 'failed_days' => 1, 'events_days' => 1]);
});
