<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Envelopes\RawEnvelope;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
});

function rawRequest(): Request
{
    $request = Http::recorded()->last()[0];
    assert($request instanceof Request);

    return $request;
}

it('is registered beside cbox and cloudevents', function (): void {
    expect(app(EnvelopeFormats::class)->names())->toContain('cbox', 'cloudevents', RawEnvelope::NAME)
        ->and(RawEnvelope::NAME)->toBe('raw');
});

it('sends the payload alone, signed, with the id and type in headers', function (string $scheme): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], scheme: $scheme, envelope: 'raw');
    $this->fakeWebhookReceiver('inbound', $registered->secret, $scheme);

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1', 'url' => 'https://a.example/ø']));

    $request = rawRequest();
    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($request->body())->toBe('{"id":"usr_1","url":"https://a.example/ø"}')
        ->and($request->header('Content-Type')[0] ?? '')->toStartWith('application/json')
        ->and($request->header('X-Webhook-Event'))->toBe(['user.created'])
        ->and($request->header('X-Webhook-Delivery'))->toBe([$delivery->id])
        ->and(app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()))->scheme)->toBe($scheme);
})->with(['cbox', 'standard-webhooks']);

it('sends an empty payload as an empty object', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], envelope: 'raw');

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, []));

    expect(rawRequest()->body())->toBe('{}');
});

it('sends the transform result as the whole body', function (): void {
    $this->fakePayloadTransformer()->define('wrap', static fn (array $input): array => ['events' => [['event_id' => $input['id'], 'name' => $input['type']]]]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], envelope: 'raw', transform: 'wrap');

    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1']));

    expect(json_decode(rawRequest()->body(), true))->toBe(['events' => [['event_id' => $published->id, 'name' => 'user.created']]]);
});

it('sends a batch as a JSON array of payloads', function (): void {
    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], batchSize: 2, batchWindowSeconds: 60, envelope: 'raw');

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'a']));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'b']));

    expect(rawRequest()->body())->toBe('[{"id":"a"},{"id":"b"}]');
});
