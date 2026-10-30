<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\BatchFlusher;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Jobs\DeliverBatch;
use Cbox\Webhooks\Jobs\FlushEndpointBatches;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('org_1');
});

function publishBatchItems(Scope $scope, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $scope, ['i' => $i]));
    }
}

it('sends one request with every item once the batch size is reached', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 3, batchWindowSeconds: 60);

    publishBatchItems($this->scope, 2);
    $this->assertWebhookNotDelivered('https://receiver.test/batch');
    expect(WebhookDelivery::query()->where('status', DeliveryStatus::Pending->value)->count())->toBe(2);

    publishBatchItems($this->scope, 1);

    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 3);
    [$request] = $this->recordedWebhookRequests('https://receiver.test/batch')[0];
    $envelope = json_decode($request->body(), true);

    expect($envelope['batch'])->toBeTrue()
        ->and($envelope['count'])->toBe(3)
        ->and(array_column($envelope['deliveries'], 'sequence'))->toBe([1, 2, 3])
        ->and($request->hasHeader('X-Webhook-Delivery', $envelope['delivery_id']))->toBeTrue()
        ->and($request->hasHeader('X-Webhook-Sequence', '1-3'))->toBeTrue()
        ->and($request->hasHeader('X-Webhook-Event', 'batch'))->toBeTrue()
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(3)
        ->and(WebhookDelivery::query()->distinct()->count('batch_id'))->toBe(1)
        ->and(WebhookAttempt::query()->count())->toBe(3)
        ->and(WebhookAttempt::query()->distinct()->count('batch_id'))->toBe(1);
});

it('flushes a partial batch once the window has elapsed, via the sweep', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 10, batchWindowSeconds: 30);
    Bus::fake([FlushEndpointBatches::class]);

    publishBatchItems($this->scope, 2);
    Bus::assertDispatched(FlushEndpointBatches::class, fn (FlushEndpointBatches $job): bool => ! $job->immediate);

    expect(app(BatchFlusher::class)->flush())->toBe(0);

    CarbonImmutable::setTestNow('2026-10-30 12:00:31');
    expect($this->deliverPendingWebhooks()->batchesQueued)->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 2);
});

it('runs the delayed flush job itself when the window elapses', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 10, batchWindowSeconds: 30);
    Bus::fake([FlushEndpointBatches::class]);
    publishBatchItems($this->scope, 2);

    CarbonImmutable::setTestNow('2026-10-30 12:00:31');
    (new FlushEndpointBatches($registered->endpoint->id, false, 30))->handle(app(BatchFlusher::class));

    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 2);
});

it('settles every delivery of a failed batch alike and retries them together', function (): void {
    Http::fake(['receiver.test/*' => Http::sequence()->push('', 500)->push('', 200)]);
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 2, batchWindowSeconds: 60);

    publishBatchItems($this->scope, 2);

    $deliveries = WebhookDelivery::query()->orderBy('sequence')->get();
    expect($deliveries->pluck('status')->unique()->all())->toBe([DeliveryStatus::Failed])
        ->and($deliveries->pluck('attempt')->unique()->all())->toBe([1])
        ->and($deliveries->pluck('next_attempt_at')->map(fn ($d) => $d?->toDateTimeString())->unique()->all())->toBe(['2026-10-30 12:00:10'])
        ->and(WebhookAttempt::query()->count())->toBe(2)
        ->and(WebhookAttempt::query()->pluck('response_code')->unique()->all())->toBe([500]);

    CarbonImmutable::setTestNow('2026-10-30 12:00:10');
    $report = $this->deliverPendingWebhooks();

    expect($report->batchesQueued)->toBe(1)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(2)
        ->and(WebhookDelivery::query()->pluck('attempt')->unique()->all())->toBe([2])
        ->and(WebhookAttempt::query()->count())->toBe(4);
    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 4);
});

it('groups idempotently: a second flush finds nothing and a batch job for a settled batch sends nothing', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 2);
    Bus::fake([DeliverBatch::class, FlushEndpointBatches::class]);
    publishBatchItems($this->scope, 2);

    expect(app(BatchFlusher::class)->flush())->toBe(1)
        ->and(app(BatchFlusher::class)->flush())->toBe(0);

    $batchId = WebhookDelivery::query()->firstOrFail()->batch_id;
    expect($batchId)->not->toBeNull()
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::InFlight->value)->count())->toBe(2);

    Bus::assertDispatchedTimes(DeliverBatch::class, 1);

    $result = app(Deliverer::class)->deliverBatch((string) $batchId);
    expect($result?->succeeded())->toBeTrue()
        ->and(app(Deliverer::class)->deliverBatch((string) $batchId))->toBeNull();
    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 2);
});

it('splits a large backlog into several full batches in sequence order', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 2, batchWindowSeconds: 300);
    Bus::fake([FlushEndpointBatches::class]);
    publishBatchItems($this->scope, 5);

    expect(app(BatchFlusher::class)->flush())->toBe(2);

    $sequences = array_map(static fn (array $pair): int => $pair[1]['sequence'], $this->recordedWebhookRequests('https://receiver.test/batch'));
    expect($sequences)->toBe([1, 2, 3, 4])
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Pending->value)->count())->toBe(1);

    expect(app(BatchFlusher::class)->flush(force: true))->toBe(1);
    $this->assertWebhookDeliveredTimes('https://receiver.test/batch', 5);
});

it('signs the batch body and uses the batch id as the standard-webhooks message id', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], scheme: 'standard-webhooks', batchSize: 2, batchWindowSeconds: 60);
    publishBatchItems($this->scope, 2);

    [$request] = $this->recordedWebhookRequests('https://receiver.test/batch')[0];
    $envelope = json_decode($request->body(), true);

    expect($request->header('webhook-id')[0])->toBe($envelope['delivery_id']);

    $this->fakeWebhookEndpoint('inbound', 'standard-webhooks', $registered->secret);
    $verified = app(Webhooks::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()));
    expect($verified->eventId)->toBe($envelope['delivery_id']);
});

it('rescues a stranded batch and lets the flusher regroup it', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/batch', ['user.created'], batchSize: 2);
    Bus::fake([DeliverBatch::class, FlushEndpointBatches::class]);
    publishBatchItems($this->scope, 2);
    app(BatchFlusher::class)->flush();

    CarbonImmutable::setTestNow('2026-10-30 12:20:00');
    Bus::fake();
    $report = $this->deliverPendingWebhooks();

    expect($report->strandedRescued)->toBe(2)
        ->and($report->batchesQueued)->toBe(1)
        ->and(WebhookDelivery::query()->pluck('attempt')->unique()->all())->toBe([2]);
});
