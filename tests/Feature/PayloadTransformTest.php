<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\PayloadTransformer;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Events\DeliveryExhausted;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\TransformFailed;
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Testing\FakePayloadTransformer;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-30 12:00:00');
    $this->scope = Scope::of('shop', 'org_a');
    $this->fakeDnsForUrl('https://receiver.test/hooks');
});

/** @return array<string, mixed> */
function lastBody(): array
{
    $request = Http::recorded()->last()[0];
    assert($request instanceof Request);

    return (array) json_decode($request->body(), true);
}

it('refuses every transform until the host binds a transformer', function (): void {
    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], transform: 'data.id')))
        ->toThrow(InvalidEndpoint::class, 'no payload transformer is configured');
});

it('validates the expression on register and update', function (): void {
    $transformer = $this->fakePayloadTransformer();
    $transformer->refuse('bad(');

    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], transform: 'bad(')))
        ->toThrow(InvalidEndpoint::class, 'bad(');

    $registered = app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], transform: 'data'));

    expect($registered->endpoint->transform)->toBe('data')
        ->and(fn () => app(Endpoints::class)->update($this->scope, $registered->endpoint->id, new EndpointChanges(transform: 'bad(')))->toThrow(InvalidEndpoint::class)
        ->and(app(Endpoints::class)->update($this->scope, $registered->endpoint->id, new EndpointChanges(clearTransform: true))?->transform)->toBeNull()
        ->and((new EndpointChanges(clearTransform: true))->isEmpty())->toBeFalse();
});

it('refuses an expression over 4000 characters', function (): void {
    $this->fakePayloadTransformer();

    expect(fn () => app(Endpoints::class)->register($this->scope, new NewEndpoint('https://receiver.test/hooks', ['user.created'], transform: str_repeat('a', 4001))))
        ->toThrow(InvalidEndpoint::class);
});

it('hands the transformer the event and puts the result in data', function (string $envelope): void {
    $transformer = $this->fakePayloadTransformer();
    $transformer->define('pick', static fn (array $input): array => ['who' => $input['data']['id'], 'type' => $input['type'], 'subject' => $input['subject'], 'at' => $input['occurred_at']]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], envelope: $envelope, transform: 'pick');

    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1', 'secret' => 'x'], subject: 'person_1'));

    expect(lastBody()['data'])->toBe(['who' => 'usr_1', 'type' => 'user.created', 'subject' => 'person_1', 'at' => '2026-10-30T12:00:00+00:00'])
        ->and($transformer->inputs()[0]['id'])->toBe($published->id);
})->with(['cbox', 'cloudevents']);

it('fails permanently when the transform throws, without a retry', function (): void {
    $transformer = $this->fakePayloadTransformer();
    $transformer->define('explode', static fn (array $input): never => throw new TransformFailed(str_repeat('no such field ', 200)));
    Event::fake([DeliveryExhausted::class]);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], transform: 'explode');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1']));

    $delivery = WebhookDelivery::query()->firstOrFail();
    $attempt = WebhookAttempt::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Exhausted)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_error)->toStartWith('transform_failed: no such field')
        ->and($attempt->outcome)->toBe(AttemptOutcome::TransformFailed)
        ->and(mb_strlen((string) $attempt->error))->toBeLessThanOrEqual(1000)
        ->and(Http::recorded())->toHaveCount(0);

    Event::assertDispatched(DeliveryExhausted::class, fn (DeliveryExhausted $e): bool => $e->reason === 'transform_failed');

    // Nothing for the sweep to pick up later, and the breaker never heard of it.
    CarbonImmutable::setTestNow('2026-10-31 12:00:00');
    expect($this->deliverPendingWebhooks()->retriesQueued)->toBe(0)
        ->and(AttemptOutcome::TransformFailed->countsAgainstEndpoint())->toBeFalse();
});

it('fails permanently when the transform yields null', function (): void {
    $this->fakePayloadTransformer()->define('nothing', static fn (array $input): mixed => null);

    $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], transform: 'nothing');
    app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => 'usr_1']));

    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(DeliveryStatus::Exhausted)
        ->and(WebhookAttempt::query()->firstOrFail()->outcome)->toBe(AttemptOutcome::TransformFailed);
});

it('transforms each event of a batch and drops only the one that failed', function (): void {
    $this->fakePayloadTransformer()->define('id-or-fail', static function (array $input): mixed {
        $id = $input['data']['id'];

        return $id === 'bad' ? throw new TransformFailed('bad one') : ['ref' => $id];
    });

    $registered = $this->registerWebhookEndpoint($this->scope, 'https://receiver.test/hooks', ['user.created'], batchSize: 3, batchWindowSeconds: 60, transform: 'id-or-fail');

    foreach (['a', 'bad', 'c'] as $id) {
        app(Publisher::class)->publish(new OutboundEvent('user.created', $this->scope, ['id' => $id]));
    }

    $body = lastBody();

    expect($body['count'])->toBe(2)
        ->and(array_column($body['deliveries'], 'data'))->toBe([['ref' => 'a'], ['ref' => 'c']])
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Exhausted->value)->count())->toBe(1)
        ->and(WebhookDelivery::query()->where('status', DeliveryStatus::Delivered->value)->count())->toBe(2)
        ->and($registered->endpoint->isBatching())->toBeTrue();
});

it('is bound through the contract so a host can replace it', function (): void {
    $fake = new FakePayloadTransformer;
    app()->instance(PayloadTransformer::class, $fake);

    expect(app(PayloadTransformer::class))->toBe($fake);
});
