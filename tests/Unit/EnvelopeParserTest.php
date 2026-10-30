<?php

declare(strict_types=1);

use Cbox\Webhooks\Exceptions\MalformedEnvelope;
use Cbox\Webhooks\Receiving\EnvelopeParser;
use Cbox\Webhooks\Receiving\InMemoryReceivers;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;

it('parses a single envelope into an incoming webhook', function (): void {
    $items = (new EnvelopeParser)->parse('cbox-id', json_encode([
        'id' => 'evt_1', 'type' => 'user.created', 'created_at' => '2026-10-30T12:00:00+00:00', 'delivery_id' => 'dlv_1',
        'attempt' => 2, 'sequence' => 41, 'scope' => ['partition' => 'env_1', 'key' => 'org_1'], 'api_version' => '2026-10-01', 'data' => ['x' => 1],
    ]) ?: '');

    expect($items)->toHaveCount(1);
    $item = $items[0];
    expect($item->id)->toBe('evt_1')
        ->and($item->type)->toBe('user.created')
        ->and($item->deliveryId)->toBe('dlv_1')
        ->and($item->sequence)->toBe(41)
        ->and($item->attempt)->toBe(2)
        ->and($item->scope->toArray())->toBe(['partition' => 'env_1', 'key' => 'org_1'])
        ->and($item->apiVersion)->toBe('2026-10-01')
        ->and($item->data)->toBe(['x' => 1])
        ->and($item->occurredAt?->toIso8601String())->toBe('2026-10-30T12:00:00+00:00')
        ->and($item->endpointName)->toBe('cbox-id')
        ->and($item->raw['id'])->toBe('evt_1');
});

it('parses a batch and refuses malformed shapes', function (): void {
    $parser = new EnvelopeParser;

    $items = $parser->parse('x', json_encode(['batch' => true, 'delivery_id' => 'b', 'count' => 2, 'deliveries' => [
        ['type' => 'a.b', 'delivery_id' => '1'],
        ['type' => 'a.c', 'delivery_id' => '2', 'created_at' => 'garbage'],
    ]]) ?: '');

    expect($items)->toHaveCount(2)->and($items[1]->occurredAt)->toBeNull();

    expect(fn () => $parser->parse('x', 'nope'))->toThrow(MalformedEnvelope::class)
        ->and(fn () => $parser->parse('x', '[1,2]'))->toThrow(MalformedEnvelope::class)
        ->and(fn () => $parser->parse('x', '{"type":"a"}'))->toThrow(MalformedEnvelope::class)
        ->and(fn () => $parser->parse('x', '{"batch":true}'))->toThrow(MalformedEnvelope::class)
        ->and(fn () => $parser->parse('x', '{"batch":true,"deliveries":[{"type":"a"}]}'))->toThrow(MalformedEnvelope::class);
});

it('routes handlers by exact type, group wildcard and star', function (): void {
    $receivers = new InMemoryReceivers;
    $a = static fn (IncomingWebhook $w) => null;
    $b = static fn (IncomingWebhook $w) => null;
    $c = static fn (IncomingWebhook $w) => null;

    $receivers->on('user.created', $a);
    $receivers->on('user.*', $b);
    $receivers->on('*', $c);

    expect($receivers->handlersFor('user.created'))->toBe([$a, $b, $c])
        ->and($receivers->handlersFor('user.deleted'))->toBe([$b, $c])
        ->and($receivers->handlersFor('invoice.paid'))->toBe([$c])
        ->and($receivers->hasHandlersFor('invoice.paid'))->toBeTrue()
        ->and((new InMemoryReceivers)->hasHandlersFor('x'))->toBeFalse();
});
