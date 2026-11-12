<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Delivery\HttpTransport;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
});

function publishUser(Scope $scope): WebhookDelivery
{
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 'usr_1']));

    return WebhookDelivery::query()->orderByDesc('created_at')->orderByDesc('sequence')->firstOrFail();
}

it('stores and shows the delivery policy, null meaning the configured default', function (): void {
    $plain = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/plain', ['user.created']);

    expect($plain->endpoint->retrySchedule)->toBeNull()
        ->and($plain->endpoint->timeoutSeconds)->toBeNull()
        ->and($plain->endpoint->followRedirects)->toBeFalse()
        ->and($plain->endpoint->transform)->toBeNull();

    $tuned = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/tuned', ['user.created'], retrySchedule: [10, 60, 300], timeoutSeconds: 5, followRedirects: true);

    $found = app(Endpoints::class)->find($this->scope, $tuned->endpoint->id);

    expect($found?->retrySchedule)->toBe([10, 60, 300])
        ->and($found?->timeoutSeconds)->toBe(5)
        ->and($found?->followRedirects)->toBeTrue()
        ->and($found?->toArray())->toMatchArray(['retry_schedule' => [10, 60, 300], 'timeout_seconds' => 5, 'follow_redirects' => true, 'transform' => null]);
});

it('changes and clears the delivery policy', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], retrySchedule: [10], timeoutSeconds: 5);
    $endpoints = app(Endpoints::class);
    $id = $registered->endpoint->id;

    expect((new EndpointChanges(retrySchedule: []))->isEmpty())->toBeFalse()
        ->and((new EndpointChanges(clearTimeout: true))->isEmpty())->toBeFalse()
        ->and((new EndpointChanges(followRedirects: false))->isEmpty())->toBeFalse();

    $changed = $endpoints->update($this->scope, $id, new EndpointChanges(retrySchedule: [], timeoutSeconds: 30, followRedirects: true));

    expect($changed?->retrySchedule)->toBe([])
        ->and($changed?->timeoutSeconds)->toBe(30)
        ->and($changed?->followRedirects)->toBeTrue();

    $cleared = $endpoints->update($this->scope, $id, new EndpointChanges(clearRetrySchedule: true, clearTimeout: true, followRedirects: false));

    expect($cleared?->retrySchedule)->toBeNull()
        ->and($cleared?->timeoutSeconds)->toBeNull()
        ->and($cleared?->followRedirects)->toBeFalse();
});

it('refuses a schedule or a timeout out of bounds', function (array $schedule, ?int $timeout): void {
    $this->fakeDnsForUrl('https://receiver.test/hooks');

    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], retrySchedule: $schedule, timeoutSeconds: $timeout)))
        ->toThrow(InvalidEndpoint::class);
})->with([
    'a zero delay' => [[0], null],
    'over a week' => [[604_801], null],
    'over twenty entries' => [array_fill(0, 21, 10), null],
    'a zero timeout' => [[], 0],
    'over two minutes' => [[], 121],
]);

it('refuses an out-of-bounds change too', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    expect(fn () => app(Endpoints::class)->update($this->scope, $registered->endpoint->id, new EndpointChanges(timeoutSeconds: 500)))->toThrow(InvalidEndpoint::class)
        ->and(fn () => app(Endpoints::class)->update($this->scope, $registered->endpoint->id, new EndpointChanges(retrySchedule: [-1])))->toThrow(InvalidEndpoint::class);
});

it('lets the host lower the highest timeout an endpoint may choose', function (): void {
    config(['webhooks.http.max_endpoint_timeout' => 30]);
    $this->fakeDnsForUrl('https://receiver.test/hooks');

    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], timeoutSeconds: 31)))->toThrow(InvalidEndpoint::class);
});

it('retries on the endpoint schedule instead of the configured one', function (): void {
    config(['webhooks.retry.schedule' => [10, 20, 30]]);
    Http::fake(['receiver.test/*' => Http::response('', 500)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], retrySchedule: [5, 7]);

    $delivery = publishUser($this->scope);
    expect($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:05');

    CarbonImmutable::setTestNow('2026-10-30 12:00:05');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:12');

    CarbonImmutable::setTestNow('2026-10-30 12:00:12');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted)->and($delivery->attempt)->toBe(3);
});

it('tries once and stops with an empty schedule, even on a 429 with Retry-After', function (int $status, array $headers): void {
    Http::fake(['receiver.test/*' => Http::response('', $status, $headers)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], retrySchedule: []);

    $delivery = publishUser($this->scope);

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)->and($delivery->attempt)->toBe(1);
})->with([
    'a 500' => [500, []],
    'a 429' => [429, ['Retry-After' => '5']],
]);

it('stops honouring Retry-After once the schedule is spent', function (): void {
    config(['webhooks.retry.schedule' => [10]]);
    Http::fake(['receiver.test/*' => Http::response('', 429, ['Retry-After' => '3'])]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    $delivery = publishUser($this->scope);
    expect($delivery->status)->toBe(DeliveryStatus::Failed)->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:03');

    CarbonImmutable::setTestNow('2026-10-30 12:00:03');
    $this->deliverPendingWebhooks();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Exhausted)->and($delivery->attempt)->toBe(2);
});

it('sends with the endpoint timeout, or the configured one', function (): void {
    config(['webhooks.http.timeout' => 9]);
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[$request->url()] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/fast', ['user.created'], timeoutSeconds: 3);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/default', ['user.created']);

    publishUser($this->scope);

    expect($timeouts)->toBe(['https://receiver.test/fast' => 3, 'https://receiver.test/default' => 9]);
});

it('sizes the job timeout for the highest timeout an endpoint may choose', function (): void {
    config(['webhooks.http.connect_timeout' => 3, 'webhooks.http.timeout' => 7, 'webhooks.http.max_endpoint_timeout' => 20]);

    expect(app(HttpTransport::class)->timeoutBudgetSeconds())->toBe(23);
});

it('keeps the policy columns on the model', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], retrySchedule: [1, 2], timeoutSeconds: 4, followRedirects: true);

    $row = WebhookEndpoint::query()->firstOrFail();

    expect($row->retry_schedule)->toBe([1, 2])
        ->and($row->timeout_seconds)->toBe(4)
        ->and($row->follow_redirects)->toBeTrue()
        ->and($row->retrySchedule())->toBe([1, 2]);
});
