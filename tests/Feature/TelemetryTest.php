<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Telemetry\Facades\Telemetry;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\Telemetry\Testing\TelemetryFake;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Http\Controllers\ReceiveWebhookController;
use Cbox\Webhooks\Monitoring\LogMetrics;
use Cbox\Webhooks\Monitoring\NullMetrics;
use Cbox\Webhooks\Monitoring\NullTracing;
use Cbox\Webhooks\Telemetry\TelemetryHooks;
use Cbox\Webhooks\Telemetry\TelemetryMetrics;
use Cbox\Webhooks\Telemetry\TelemetryTracing;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * The telemetry package is a dev dependency, present here; the provider binds
 * the adapter only when `webhooks.telemetry.enabled` is on, which the base
 * TestCase turns off. These tests turn it on and swap in the fake.
 */
function enableTelemetry(): TelemetryFake
{
    config(['webhooks.telemetry.enabled' => true, 'telemetry.enabled' => true]);
    app()->register(TelemetryServiceProvider::class);
    app()->forgetInstance(Metrics::class);
    app()->forgetInstance(Tracing::class);

    $fake = Telemetry::fake();
    app()->instance(TelemetryManager::class, $fake);

    // The provider wired the hooks at boot only if telemetry was on then; wire them to the fake now.
    $hooks = app(TelemetryHooks::class);
    $hooks->subscribe(app(Dispatcher::class));
    $hooks->registerGauges();

    return $fake;
}

it('binds the null adapters when telemetry is disabled', function (): void {
    expect(app(Metrics::class))->toBeInstanceOf(NullMetrics::class)
        ->and(app(Tracing::class))->toBeInstanceOf(NullTracing::class);
});

it('binds the telemetry adapters when the package is present and enabled', function (): void {
    enableTelemetry();

    expect(app(Metrics::class))->toBeInstanceOf(TelemetryMetrics::class)
        ->and(app(Tracing::class))->toBeInstanceOf(TelemetryTracing::class);
});

it('binds a host-named metrics class over everything else', function (): void {
    config(['webhooks.metrics' => LogMetrics::class]);
    app()->forgetInstance(Metrics::class);

    expect(app(Metrics::class))->toBeInstanceOf(LogMetrics::class);
});

it('records counters, histograms and spans for a delivery and propagates the trace', function (): void {
    $fake = enableTelemetry();
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    $fake->span('test.root', function () use ($scope): void {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['id' => 1]));
    });

    $fake->assertCounterIncremented('webhooks_published_total', ['type' => 'user.created']);
    $fake->assertCounterIncremented('webhooks_deliveries_total', ['outcome' => 'success', 'code_class' => '2xx']);
    $fake->assertHistogramRecorded('webhooks_delivery_duration_ms', ['outcome' => 'success']);
    $fake->assertHistogramRecorded('webhooks_delivery_attempts', ['outcome' => 'delivered']);
    expect($fake->histogramSum('webhooks_delivery_attempts', ['outcome' => 'delivered']))->toBe(1.0);

    $fake->assertSpanRecorded('webhooks.publish', fn (Span $span): bool => $span->attributes()['webhook.type'] === 'user.created' && $span->attributes()['webhook.deliveries'] === 1);
    $fake->assertSpanRecorded('webhooks.deliver', fn (Span $span): bool => $span->kind === SpanKind::Client
        && $span->attributes()['http.response.status_code'] === 200
        && $span->attributes()['webhook.outcome'] === 'success'
        && $span->attributes()['server.address'] === 'receiver.test'
        && $span->attributes()['webhook.attempt'] === 1
        && ! str_contains(json_encode($span->attributes()) ?: '', '/hooks'));

    $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.created', fn (Request $request): bool => preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/', $request->header('traceparent')[0] ?? '') === 1);

    $fake->assertGaugeSet('webhooks_pending');
    $fake->assertGaugeSet('webhooks_endpoints', ['status' => 'active']);
    expect($fake->gaugeValue('webhooks_endpoints', ['status' => 'active']))->toBe(1.0);
});

it('emits telemetry events for a trip, a recovery, a disable and an exhaustion', function (): void {
    $fake = enableTelemetry();
    config(['webhooks.breaker.failure_threshold' => 1, 'webhooks.retry.schedule' => []]);
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 410)]);
    $scope = Scope::of('org_1');
    $this->registerWebhookEndpoint($scope, 'https://receiver.test/hooks', ['user.created']);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, [], 'a'));

    $fake->assertEventEmitted('webhooks.endpoint.tripped');
    $fake->assertEventEmitted('webhooks.delivery.exhausted', fn ($e): bool => $e->attributes['webhook.reason'] === 'retries_exhausted');
    $fake->assertCounterIncremented('webhooks_endpoint_trips_total');

    CarbonImmutable::setTestNow(now()->addHour());
    app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, [], 'b'));

    $fake->assertEventEmitted('webhooks.endpoint.disabled', fn ($e): bool => $e->attributes['webhook.reason'] === 'gone');
    $fake->assertCounterIncremented('webhooks_endpoints_disabled_total', ['reason' => 'gone']);
});

it('continues the sender trace on the receiver and records a server span', function (): void {
    $fake = enableTelemetry();
    Route::post('/hooks/cbox-id', ReceiveWebhookController::class)->middleware('webhooks.receive:cbox-id');
    $this->fakeWebhookReceiver('cbox-id', 'shh');
    $this->onWebhook('user.created', fn (IncomingWebhook $w) => null);

    $body = json_encode($this->webhookEnvelope('user.created', ['id' => 1], 'dlv_1'));
    $this->postSignedWebhook('/hooks/cbox-id', 'cbox-id', (string) $body, null, ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])->assertStatus(202);

    $fake->assertSpanRecorded('webhooks.receive', fn (Span $span): bool => $span->kind === SpanKind::Server
        && $span->traceId === '0af7651916cd43dd8448eb211c80319c'
        && $span->attributes()['webhook.delivery_id'] === 'dlv_1'
        && $span->attributes()['webhook.outcome'] === 'processed');
    $fake->assertCounterIncremented('webhooks_inbound_total', ['endpoint' => 'cbox-id', 'outcome' => 'processed']);
    $fake->assertHistogramRecorded('webhooks_inbound_duration_ms', ['endpoint' => 'cbox-id']);
});
