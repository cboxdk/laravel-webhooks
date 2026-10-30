<?php

declare(strict_types=1);

use Cbox\Webhooks\Enums\InboundStatus;
use Cbox\Webhooks\Events\WebhookDuplicate;
use Cbox\Webhooks\Events\WebhookGapDetected;
use Cbox\Webhooks\Events\WebhookProcessingFailed;
use Cbox\Webhooks\Events\WebhookReceived;
use Cbox\Webhooks\Http\Controllers\ReceiveWebhookController;
use Cbox\Webhooks\Jobs\ProcessInboundWebhook;
use Cbox\Webhooks\Models\WebhookInboundDelivery;
use Cbox\Webhooks\Receiving\InboundLedger;
use Cbox\Webhooks\Receiving\InboundProcessor;
use Cbox\Webhooks\Tests\Fixtures\RecordingHandler;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Exceptions\ConfigurationError;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::post('/hooks/cbox-id', ReceiveWebhookController::class)->middleware('webhooks.receive:cbox-id');
    $this->fakeWebhookReceiver('cbox-id', 'shh');
    RecordingHandler::$seen = [];
    $this->withoutExceptionHandling();
});

it('verifies, records and handles a delivery, and answers 202', function (): void {
    Event::fake([WebhookReceived::class]);
    $this->onWebhook('user.created', RecordingHandler::class);
    $this->onWebhook('user.*', function (IncomingWebhook $webhook): void {
        expect($webhook->endpointName)->toBe('cbox-id');
    });

    $response = $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', ['id' => 'usr_1'], 'dlv_1', 7, Scope::of('org_1', 'env_1'));

    $response->assertStatus(202)->assertJson(['status' => 'accepted', 'received' => 1, 'duplicates' => 0]);
    $this->assertWebhookHandled('user.created', fn (IncomingWebhook $w): bool => $w->data === ['id' => 'usr_1']
        && $w->deliveryId === 'dlv_1'
        && $w->sequence === 7
        && $w->scope->equals(Scope::of('org_1', 'env_1'))
        && $w->occurredAt !== null);
    expect(RecordingHandler::$seen)->toHaveCount(1);

    $row = WebhookInboundDelivery::query()->firstOrFail();
    expect($row->status)->toBe(InboundStatus::Processed)
        ->and($row->delivery_id)->toBe('dlv_1')
        ->and($row->endpoint_name)->toBe('cbox-id')
        ->and($row->processed_at)->not->toBeNull();
    Event::assertDispatched(WebhookReceived::class);
});

it('answers 200 duplicate for a delivery id seen before without handling it again', function (): void {
    Event::fake([WebhookDuplicate::class]);
    $this->onWebhook('user.created', RecordingHandler::class);

    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', ['id' => 1], 'dlv_1')->assertStatus(202);
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', ['id' => 1], 'dlv_1')
        ->assertOk()->assertJson(['status' => 'duplicate', 'received' => 0, 'duplicates' => 1]);

    expect(RecordingHandler::$seen)->toHaveCount(1)->and(WebhookInboundDelivery::query()->count())->toBe(1);
    Event::assertDispatched(WebhookDuplicate::class, fn (WebhookDuplicate $e): bool => $e->deliveryId === 'dlv_1');

    // The same id on another endpoint name is a different delivery.
    Route::post('/hooks/billing', ReceiveWebhookController::class)->middleware('webhooks.receive:billing');
    $this->fakeWebhookReceiver('billing', 'other');
    $this->postWebhook('/hooks/billing', 'billing', 'user.created', ['id' => 1], 'dlv_1')->assertStatus(202);
});

it('handles a batch item by item and reports duplicates inside it', function (): void {
    $this->onWebhook('*', RecordingHandler::class);
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'dlv_1');

    $this->postWebhookBatch('/hooks/cbox-id', 'cbox-id', [
        ['type' => 'user.created', 'delivery_id' => 'dlv_1'],
        ['type' => 'user.deleted', 'delivery_id' => 'dlv_2', 'data' => ['id' => 2]],
        ['type' => 'invoice.paid', 'delivery_id' => 'dlv_3'],
    ])->assertStatus(202)->assertJson(['received' => 2, 'duplicates' => 1]);

    expect(RecordingHandler::$seen)->toHaveCount(3);
    $this->assertWebhookHandled('user.deleted', fn (IncomingWebhook $w): bool => $w->data === ['id' => 2]);
    $this->assertWebhookHandled('invoice.paid');
});

it('marks the row failed, fires the event and answers 500 when an inline handler throws', function (): void {
    Event::fake([WebhookProcessingFailed::class]);
    $this->onWebhook('user.created', function (): void {
        throw new RuntimeException('cannot');
    });

    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'dlv_1')->assertStatus(500)->assertJson(['status' => 'failed']);

    $row = WebhookInboundDelivery::query()->firstOrFail();
    expect($row->status)->toBe(InboundStatus::Failed)->and($row->error)->toContain('cannot');
    Event::assertDispatched(WebhookProcessingFailed::class);
    $this->assertWebhookNotHandled('user.created');

    // The sender's retry is a duplicate by id: it must be replayed by hand, not reprocessed blindly.
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'dlv_1')->assertOk();
});

it('queues processing by default and the job rethrows so the queue retries', function (): void {
    config(['webhooks.receive.inline' => false, 'webhooks.receive.queue' => 'hooks']);
    Queue::fake();
    $this->onWebhook('user.created', RecordingHandler::class);

    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', ['id' => 1], 'dlv_1')->assertStatus(202);

    Queue::assertPushedOn('hooks', ProcessInboundWebhook::class, fn (ProcessInboundWebhook $job): bool => $job->webhook->deliveryId === 'dlv_1');
    expect(RecordingHandler::$seen)->toBe([])
        ->and(WebhookInboundDelivery::query()->firstOrFail()->status)->toBe(InboundStatus::Received);

    $ledgerId = WebhookInboundDelivery::query()->firstOrFail()->id;
    $job = new ProcessInboundWebhook($ledgerId, new IncomingWebhook(type: 'user.created', deliveryId: 'dlv_1', endpointName: 'cbox-id'));
    $job->handle(app(InboundProcessor::class), app(InboundLedger::class));

    expect(RecordingHandler::$seen)->toHaveCount(1)
        ->and(WebhookInboundDelivery::query()->firstOrFail()->status)->toBe(InboundStatus::Processed);

    $this->onWebhook('user.deleted', fn () => throw new RuntimeException('boom'));
    $failing = new ProcessInboundWebhook($ledgerId, new IncomingWebhook(type: 'user.deleted', deliveryId: 'dlv_1', endpointName: 'cbox-id'));
    expect(fn () => $failing->handle(app(InboundProcessor::class), app(InboundLedger::class)))->toThrow(RuntimeException::class);
    expect(WebhookInboundDelivery::query()->firstOrFail()->status)->toBe(InboundStatus::Failed);
});

it('detects a sequence gap per endpoint and scope', function (): void {
    Event::fake([WebhookGapDetected::class]);
    $scope = Scope::of('org_1', 'env_1');

    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'a', 1, $scope);
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'b', 2, $scope);
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'c', 5, $scope);
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'd', 1, Scope::of('org_2', 'env_1'));

    Event::assertDispatchedTimes(WebhookGapDetected::class, 1);
    Event::assertDispatched(WebhookGapDetected::class, fn (WebhookGapDetected $e): bool => $e->expected === 3 && $e->received === 5 && $e->scope->equals($scope));
});

it('refuses an unsigned or tampered request and a malformed envelope', function (): void {
    $this->postUnsignedWebhook('/hooks/cbox-id', ['type' => 'user.created', 'delivery_id' => 'x'])->assertUnauthorized();

    $this->postSignedWebhook('/hooks/cbox-id', 'cbox-id', ['hello' => 'world'])->assertStatus(400)->assertJsonPath('status', 'rejected');
    $this->postSignedWebhook('/hooks/cbox-id', 'cbox-id', 'not json')->assertStatus(400);
    $this->postSignedWebhook('/hooks/cbox-id', 'cbox-id', ['batch' => true, 'deliveries' => []])->assertStatus(400);

    expect(WebhookInboundDelivery::query()->count())->toBe(0);
});

it('answers 500 when the receiving endpoint has no secret, so the sender keeps retrying', function (): void {
    $this->fakeWebhookEndpoint('cbox-id', 'cbox', '');

    $this->postSignedWebhook('/hooks/cbox-id', 'cbox-id', ['type' => 'user.created', 'delivery_id' => 'x'])->assertStatus(500);
})->throws(ConfigurationError::class);

it('processes an item with no handlers as processed', function (): void {
    $this->postWebhook('/hooks/cbox-id', 'cbox-id', 'user.created', [], 'dlv_1')->assertStatus(202);

    expect(WebhookInboundDelivery::query()->firstOrFail()->status)->toBe(InboundStatus::Processed);
});
