<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Envelopes\CboxEnvelope;
use Cbox\Webhooks\Envelopes\CloudEventsEnvelope;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\Http\Routes;
use Cbox\Webhooks\Jobs\DeliverWebhook;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Tests\Fixtures\RequestScopeResolver;
use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Illuminate\Bus\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    config(['webhooks.cloudevents.source' => 'https://relay.example/sources/shop']);
    $this->scope = Scope::of('shop', 'org_a');
});

/** @return list<Request> */
function requestsTo(string $url): array
{
    return array_values(array_map(
        static fn (array $pair): Request => $pair[0],
        array_filter(Http::recorded()->all(), static fn (array $pair): bool => $pair[0]->url() === $url),
    ));
}

it('sends the cbox envelope by default, byte for byte what 0.1 sent', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], apiVersion: '2026-10-01');

    expect($registered->endpoint->envelope)->toBe('cbox')
        ->and(WebhookEndpoint::query()->firstOrFail()->envelope)->toBe('cbox');

    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1', 'url' => 'https://a.example/ø']));
    $delivery = WebhookDelivery::query()->firstOrFail();
    [$request] = requestsTo('https://receiver.test/hooks');

    expect($request->body())->toBe('{"id":"'.$published->id.'","type":"user.created","created_at":"2026-10-30T12:00:00+00:00","delivery_id":"'.$delivery->id.'","attempt":1,"sequence":1,"scope":{"partition":"org_a","key":"shop"},"api_version":"2026-10-01","data":{"id":"usr_1","url":"https://a.example/ø"}}')
        ->and($request->header('Content-Type')[0])->toBe('application/json');
});

it('sends CloudEvents 1.0 structured JSON to an endpoint that chose it, and signs those exact bytes', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/ce', ['user.created'], apiVersion: '2026-10-01', envelope: 'cloudevents');
    $this->registerWebhookEndpoint($this->scope, 'https://other.test/plain', ['user.created']);

    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1'], occurredAt: CarbonImmutable::parse('2026-10-30 13:30:00', 'Europe/Copenhagen')));
    $delivery = WebhookDelivery::query()->where('endpoint_id', $registered->endpoint->id)->firstOrFail();

    [$request] = requestsTo('https://receiver.test/ce');
    $event = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($event)->toBe([
        'specversion' => '1.0',
        'id' => $published->id,
        'source' => 'https://relay.example/sources/shop',
        'type' => 'user.created',
        'time' => '2026-10-30T12:30:00Z',
        'subject' => 'org_a/shop',
        'datacontenttype' => 'application/json',
        'deliveryid' => $delivery->id,
        'sequence' => '1',
        'sequencetype' => 'Integer',
        'attempt' => 1,
        'apiversion' => '2026-10-01',
        'data' => ['id' => 'usr_1'],
    ])
        ->and($request->header('Content-Type')[0])->toBe('application/cloudevents+json; charset=utf-8')
        ->and($request->header('X-Webhook-Delivery')[0])->toBe($delivery->id)
        ->and($request->header('X-Webhook-Event')[0])->toBe('user.created');

    // The signature verifies over the recorded body as it is, and not over anything else.
    $this->fakeWebhookEndpoint('inbound', 'cbox', $registered->secret);
    $verify = fn (string $body) => app(Signatures::class)->verify('inbound', WebhookMessage::make($body, $request->headers()));

    expect($verify($request->body())->scheme)->toBe('cbox')
        ->and(fn () => $verify(json_encode($event, JSON_PRETTY_PRINT)))->toThrow(Exception::class);

    // The other endpoint of the same event still gets the cbox envelope.
    [$plain] = requestsTo('https://other.test/plain');
    expect(json_decode($plain->body(), true))->toHaveKeys(['delivery_id', 'scope'])->not->toHaveKey('specversion');
});

it('keeps data a JSON object when the payload is empty and leaves optional attributes out', function (): void {
    $this->registerWebhookEndpoint(Scope::of('solo'), 'https://receiver.test/ce', ['user.created'], envelope: 'cloudevents');

    app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('solo'), []));

    [$request] = requestsTo('https://receiver.test/ce');
    $event = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($request->body())->toContain('"data":{}')
        ->and($event['subject'])->toBe('solo')
        ->and($event)->not->toHaveKey('apiversion');
});

it('sends a batch as the CloudEvents batch format and signs it with the batch id', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/ce', ['user.created'], scheme: 'standard-webhooks', batchSize: 2, batchWindowSeconds: 60, envelope: 'cloudevents');

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['n' => 1]));
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['n' => 2]));

    [$request] = requestsTo('https://receiver.test/ce');
    $events = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($request->header('Content-Type')[0])->toBe('application/cloudevents-batch+json; charset=utf-8')
        ->and(array_is_list($events))->toBeTrue()
        ->and($events)->toHaveCount(2)
        ->and(array_column($events, 'sequence'))->toBe(['1', '2'])
        ->and(array_column($events, 'specversion'))->toBe(['1.0', '1.0'])
        ->and($events[1]['data'])->toBe(['n' => 2])
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(2);

    $this->fakeWebhookEndpoint('inbound', 'standard-webhooks', $registered->secret);
    $verified = app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()));

    expect($verified->eventId)->toBe($request->header('X-Webhook-Delivery')[0]);
});

it('refuses an unknown envelope on register and update, and switches a known one', function (): void {
    $endpoints = app(Endpoints::class);
    $this->fakeDnsForUrl('https://receiver.test/hooks');

    expect(fn () => $endpoints->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], envelope: 'protobuf')))
        ->toThrow(InvalidEndpoint::class, 'Envelope format [protobuf] is not registered; choose one of: cbox, cloudevents.');

    $registered = $endpoints->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created']));

    expect(fn () => $endpoints->update($this->scope, $registered->endpoint->id, new EndpointChanges(envelope: 'protobuf')))->toThrow(InvalidEndpoint::class)
        ->and($endpoints->update($this->scope, $registered->endpoint->id, new EndpointChanges(envelope: 'cloudevents'))?->envelope)->toBe('cloudevents')
        ->and($endpoints->find($this->scope, $registered->endpoint->id)?->toArray()['envelope'])->toBe('cloudevents')
        ->and((new EndpointChanges(envelope: 'cbox'))->isEmpty())->toBeFalse();

    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    expect(requestsTo('https://receiver.test/hooks')[0]->body())->toContain('"specversion":"1.0"');
});

it('lets a host register its own format and sends and signs whatever bytes it returns', function (): void {
    Webhooks::envelopes()->register('line', new class implements EnvelopeFormat
    {
        public function single(EnvelopeItem $item): EncodedEnvelope
        {
            return new EncodedEnvelope($item->type.' '.$item->deliveryId."\n", 'text/plain');
        }

        public function batch(string $batchId, array $items): EncodedEnvelope
        {
            return new EncodedEnvelope(implode("\n", array_map(static fn (EnvelopeItem $i): string => $i->type, $items))."\n", 'text/plain');
        }
    });

    expect(app(EnvelopeFormats::class)->names())->toBe(['cbox', 'cloudevents', 'line'])
        ->and(app(EnvelopeFormats::class)->get('cbox'))->toBeInstanceOf(CboxEnvelope::class)
        ->and(app(EnvelopeFormats::class)->get('cloudevents'))->toBeInstanceOf(CloudEventsEnvelope::class);

    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/line', ['user.created'], envelope: 'line');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));

    [$request] = requestsTo('https://receiver.test/line');
    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($request->body())->toBe("user.created {$delivery->id}\n")
        ->and($request->header('Content-Type')[0])->toBe('text/plain');

    $this->fakeWebhookEndpoint('inbound', 'cbox', $registered->secret);
    expect(app(Signatures::class)->verify('inbound', WebhookMessage::make($request->body(), $request->headers()))->scheme)->toBe('cbox');
});

it('fails loudly and keeps the delivery when an endpoint names a format that is no longer registered', function (): void {
    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created']);

    Bus::fake([DeliverWebhook::class]);
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 1]));
    $delivery = WebhookDelivery::query()->firstOrFail();

    WebhookEndpoint::query()->whereKey($registered->endpoint->id)->update(['envelope' => 'retired']);

    expect(fn () => app(Deliverer::class)->deliver($delivery->id, 0))->toThrow(InvalidEndpoint::class);

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::InFlight);

    // Back in the registry (here: the endpoint fixed), the stranded rescue sends it.
    WebhookEndpoint::query()->whereKey($registered->endpoint->id)->update(['envelope' => 'cbox']);
    Bus::swap(app(Dispatcher::class));
    CarbonImmutable::setTestNow('2026-10-30 12:20:00');
    $this->deliverPendingWebhooks();

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('accepts and validates the envelope through the management API', function (): void {
    Routes::register(app(Router::class), ['prefix' => 'api/webhooks']);
    app()->bind(ScopeResolver::class, RequestScopeResolver::class);

    $id = $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/hooks', 'event_types' => ['user.*'], 'envelope' => 'cloudevents'])
        ->assertCreated()->assertJsonPath('data.envelope', 'cloudevents')->json('data.id');

    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/hooks', 'event_types' => ['user.*'], 'envelope' => 'protobuf'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['envelope']]);

    $this->patchJson('/api/webhooks/endpoints/'.$id, ['envelope' => 'cbox'])->assertOk()->assertJsonPath('data.envelope', 'cbox');
    $this->patchJson('/api/webhooks/endpoints/'.$id, ['envelope' => 'nope'])->assertStatus(422);
    $this->postJson('/api/webhooks/endpoints', ['url' => 'https://receiver.test/hooks', 'event_types' => ['user.*']])->assertCreated()->assertJsonPath('data.envelope', 'cbox');
});
