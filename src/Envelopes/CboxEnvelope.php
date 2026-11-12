<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Envelopes;

use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;

/**
 * The package's own envelope and every endpoint's default: one JSON object per
 * delivery, a batch wrapping a list of them. The delivery id is what a receiver
 * dedupes on and `sequence` is what it orders by; `id` is the event's and stays
 * the same across every endpoint it went to. The package's receiver middleware
 * reads exactly this.
 */
final class CboxEnvelope implements EnvelopeFormat
{
    public const string NAME = 'cbox';

    public function single(EnvelopeItem $item): EncodedEnvelope
    {
        return new EncodedEnvelope(Json::encode(self::item($item)));
    }

    public function batch(string $batchId, array $items): EncodedEnvelope
    {
        return new EncodedEnvelope(Json::encode(self::wrap($batchId, array_map(self::item(...), $items))));
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(EnvelopeItem $item): array
    {
        return [
            'id' => $item->eventId,
            'type' => $item->type,
            'created_at' => $item->occurredAt->toIso8601String(),
            'delivery_id' => $item->deliveryId,
            'attempt' => $item->attempt,
            'sequence' => $item->sequence,
            'scope' => $item->scope->toArray(),
            'api_version' => $item->apiVersion,
            'data' => $item->data,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function wrap(string $batchId, array $items): array
    {
        return [
            'batch' => true,
            'delivery_id' => $batchId,
            'count' => count($items),
            'deliveries' => $items,
        ];
    }
}
