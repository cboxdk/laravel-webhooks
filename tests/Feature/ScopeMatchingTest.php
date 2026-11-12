<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * The four combinations of an owner endpoint and an event, with and without a
 * partition. Only the two where both sides agree are delivered.
 */
it('delivers to an owner endpoint only when its partition is the event\'s, nulls included', function (?string $endpointPartition, ?string $eventPartition, bool $delivered): void {
    $this->registerWebhookEndpoint(Scope::of('org_1', $endpointPartition), 'https://receiver.test/hooks', ['user.created']);

    $published = app(Publisher::class)->publish(new OutboundEvent('user.created', Scope::of('org_1', $eventPartition), ['id' => 'usr_1']));

    expect($published->deliveries)->toBe($delivered ? 1 : 0);

    $delivered
        ? $this->assertWebhookDelivered('https://receiver.test/hooks', 'user.created')
        : $this->assertWebhookNotDelivered('https://receiver.test/hooks');
})->with([
    'endpoint with, event with the same' => ['eu', 'eu', true],
    'endpoint with, event with another' => ['eu', 'us', false],
    'endpoint with, event without' => ['eu', null, false],
    'endpoint without, event with' => [null, 'eu', false],
    'endpoint without, event without' => [null, null, true],
]);

it('keeps the fan-out query and Scope::matches() in agreement for every shape of scope', function (): void {
    config()->set('webhooks.allow_wide_endpoints', true);

    $endpoints = app(Endpoints::class);
    $spec = static fn (string $name): NewEndpoint => new NewEndpoint('https://receiver.test/'.$name, ['user.created']);

    $registered = [
        $endpoints->register(Scope::of('org_1'), $spec('a'))->endpoint,
        $endpoints->register(Scope::of('org_1', 'eu'), $spec('b'))->endpoint,
        $endpoints->register(Scope::of('org_1', 'us'), $spec('c'))->endpoint,
        $endpoints->register(Scope::of('org_2', 'eu'), $spec('d'))->endpoint,
        $endpoints->registerPartitionWide('eu', $spec('e'))->endpoint,
        $endpoints->registerGlobal($spec('f'))->endpoint,
    ];

    $events = [
        Scope::of('org_1'),
        Scope::of('org_1', 'eu'),
        Scope::of('org_1', 'us'),
        Scope::of('org_2', 'eu'),
        Scope::of('org_3', 'ap'),
        Scope::partitionWide('eu'),
        Scope::global(),
    ];

    foreach ($events as $event) {
        $expected = array_values(array_map(
            static fn (Endpoint $e): string => $e->url,
            array_filter($registered, static fn (Endpoint $e): bool => $e->scope->matches($event)),
        ));

        $actual = array_map(static fn (Endpoint $e): string => $e->url, $endpoints->matching($event, 'user.created'));

        sort($expected);
        sort($actual);

        expect($actual)->toBe($expected, 'event in '.$event->toString());
    }

    // And the shape that leaked in 0.1: no partition on the endpoint, one on the event.
    expect(Scope::of('org_1')->matches(Scope::of('org_1', 'eu')))->toBeFalse()
        ->and(Scope::of('org_1', 'eu')->matches(Scope::of('org_1')))->toBeFalse()
        ->and(Scope::partitionWide('eu')->matches(Scope::of('org_1')))->toBeFalse()
        ->and(Scope::global()->matches(Scope::of('org_1', 'eu')))->toBeTrue();
});
