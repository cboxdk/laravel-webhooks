<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Events\DeliveryExhausted;
use Cbox\Webhooks\Events\DeliveryFailed;
use Cbox\Webhooks\Events\EndpointDisabled;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1');
    $this->registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);
});

function publishOne(Scope $scope, string $key = 'k1'): WebhookDelivery
{
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 1], $key));

    return WebhookDelivery::query()->orderByDesc('sequence')->firstOrFail();
}

it('schedules the first retry on the schedule after a 500 and records the attempt', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('boom', 500)->push('ok', 200)]);
    Event::fake([DeliveryFailed::class]);

    $delivery = publishOne($this->scope);

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->last_response_code)->toBe(500)
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:10');

    $attempt = WebhookAttempt::query()->firstOrFail();
    expect($attempt->outcome)->toBe(AttemptOutcome::HttpError)
        ->and($attempt->response_code)->toBe(500)
        ->and($attempt->response_excerpt)->toBe('boom')
        ->and($attempt->attempt_number)->toBe(1);
    Event::assertDispatched(DeliveryFailed::class);

    // Not due yet: the sweep leaves it alone.
    CarbonImmutable::setTestNow('2026-10-30 12:00:05');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(0);

    CarbonImmutable::setTestNow('2026-10-30 12:00:11');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(1)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->attempt)->toBe(2)
        ->and(WebhookAttempt::query()->count())->toBe(2);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 2);
});

it('walks the whole schedule and exhausts the delivery after the last entry', function (): void {
    config(['webhooks.retry.schedule' => [10, 20, 30]]);
    Http::fake(['receiver.test/*' => Http::response('no', 503)]);
    Event::fake([DeliveryExhausted::class]);

    $delivery = publishOne($this->scope);
    expect($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:10');

    CarbonImmutable::setTestNow('2026-10-30 12:00:10');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->attempt)->toBe(2)->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:30');

    CarbonImmutable::setTestNow('2026-10-30 12:00:30');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->attempt)->toBe(3)->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:01:00');

    CarbonImmutable::setTestNow('2026-10-30 12:01:00');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->attempt)->toBe(4)
        ->and($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->exhausted_at)->not->toBeNull()
        ->and($delivery->next_attempt_at)->toBeNull();

    Event::assertDispatched(DeliveryExhausted::class, fn (DeliveryExhausted $e): bool => $e->reason === 'retries_exhausted' && $e->delivery->attempt === 4);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 4);

    // Exhausted is terminal: the sweep does not pick it up again.
    CarbonImmutable::setTestNow('2026-10-30 13:00:00');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(0);
});

it('applies jitter inside the configured band', function (): void {
    config(['webhooks.retry.jitter' => 0.5, 'webhooks.retry.schedule' => [100]]);
    Http::fake(['receiver.test/*' => Http::response('no', 500)]);

    $delivery = publishOne($this->scope);
    $delay = $delivery->next_attempt_at?->getTimestamp() - CarbonImmutable::now()->getTimestamp();

    expect($delay)->toBeGreaterThanOrEqual(50)->toBeLessThanOrEqual(150);
});

it('retries a 404 on the schedule and counts it against the endpoint', function (): void {
    Http::fake(['receiver.test/*' => Http::response('gone?', 404)]);

    $delivery = publishOne($this->scope);
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($endpoint->consecutive_failures)->toBe(1)
        ->and($endpoint->first_failure_at)->not->toBeNull()
        ->and($endpoint->last_response_code)->toBe(404);
});

it('exhausts immediately on 410 Gone and disables the endpoint', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 410)]);
    Event::fake([EndpointDisabled::class, DeliveryExhausted::class]);

    publishOne($this->scope, 'a');
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Exhausted)
        ->and($endpoint->status)->toBe(EndpointStatus::Disabled)
        ->and($endpoint->disabled_reason)->toBe('gone')
        ->and($endpoint->disabled_at)->not->toBeNull();

    Event::assertDispatched(EndpointDisabled::class, fn (EndpointDisabled $e): bool => $e->reason->value === 'gone');
    Event::assertDispatched(DeliveryExhausted::class, fn (DeliveryExhausted $e): bool => $e->reason === 'gone');

    // Nothing else is sent to a disabled endpoint.
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, [], 'b'));
    expect(WebhookDelivery::query()->count())->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/hooks', 1);
});

it('honours Retry-After in seconds on 429 without counting against the breaker', function (): void {
    Http::fake(['receiver.test/*' => Http::response('slow down', 429, ['Retry-After' => '120'])]);

    $delivery = publishOne($this->scope);
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:02:00')
        ->and(WebhookAttempt::query()->firstOrFail()->outcome)->toBe(AttemptOutcome::RateLimited)
        ->and($endpoint->consecutive_failures)->toBe(0);
});

it('honours an HTTP-date Retry-After and caps it at the schedule maximum', function (): void {
    config(['webhooks.retry.schedule' => [10, 60]]);
    Http::fake(['receiver.test/*' => Http::sequence()
        ->push('', 429, ['Retry-After' => 'Fri, 30 Oct 2026 12:00:30 GMT'])
        ->push('', 429, ['Retry-After' => '99999'])]);

    $delivery = publishOne($this->scope);
    expect($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:30');

    CarbonImmutable::setTestNow('2026-10-30 12:00:30');
    $this->deliverPendingWebhooks();
    expect($delivery->refresh()->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:01:30');
});

it('falls back to the backoff on 429 without Retry-After', function (): void {
    Http::fake(['receiver.test/*' => Http::response('', 429)]);

    $delivery = publishOne($this->scope);

    expect($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:10');
});

it('classifies a timeout and a connection error and counts both against the endpoint', function (): void {
    Http::fake([
        'receiver.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'),
    ]);

    $delivery = publishOne($this->scope, 'a');
    expect(WebhookAttempt::query()->firstOrFail()->outcome)->toBe(AttemptOutcome::Timeout)
        ->and($delivery->status)->toBe(DeliveryStatus::Failed);

    Http::fake(['receiver.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);
    publishOne($this->scope, 'b');

    expect(WebhookAttempt::query()->where('outcome', AttemptOutcome::ConnectionError->value)->count())->toBe(1)
        ->and(WebhookEndpoint::query()->firstOrFail()->consecutive_failures)->toBe(2);
});

it('treats a URL the guard now refuses as a policy retry, not an endpoint failure', function (): void {
    // DNS rebinds to a private address between registration and delivery.
    $this->fakeSsrfDns(['receiver.test' => ['10.0.0.1']]);

    $delivery = publishOne($this->scope);
    $endpoint = WebhookEndpoint::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->next_attempt_at?->toDateTimeString())->toBe('2026-10-30 12:00:10')
        ->and(WebhookAttempt::query()->firstOrFail()->outcome)->toBe(AttemptOutcome::BlockedUrl)
        ->and($endpoint->consecutive_failures)->toBe(0);
    $this->assertWebhookNotDelivered('https://receiver.test/hooks');
});

it('re-signs every attempt with a fresh timestamp', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)]);

    $delivery = publishOne($this->scope);
    CarbonImmutable::setTestNow('2026-10-30 12:00:10');
    $this->deliverPendingWebhooks();

    $requests = $this->recordedWebhookRequests('https://receiver.test/hooks');
    expect($requests[0][0]->header('X-Cbox-Timestamp')[0])->toBe((string) CarbonImmutable::parse('2026-10-30 12:00:00')->getTimestamp())
        ->and($requests[1][0]->header('X-Cbox-Timestamp')[0])->toBe((string) CarbonImmutable::parse('2026-10-30 12:00:10')->getTimestamp())
        ->and($requests[1][1]['attempt'])->toBe(2)
        ->and($requests[1][1]['delivery_id'])->toBe($delivery->id);
});

it('exhausts a delivery whose endpoint was deleted by the time a worker ran', function (): void {
    $delivery = publishOne($this->scope);
    WebhookDelivery::query()->update(['status' => 'pending', 'attempt' => 0]);
    WebhookEndpoint::query()->delete();

    $result = app(Deliverer::class)->deliver($delivery->id, 0);

    expect($result?->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->refresh()->last_error)->toBe('endpoint_deleted');
});
