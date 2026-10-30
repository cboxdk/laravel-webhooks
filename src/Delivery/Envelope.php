<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Support\Json;

/**
 * The wire format. One object per delivery; a batch wraps a list of them. The
 * delivery id is what a receiver dedupes on and `sequence` is what it orders
 * by; `id` is the event's and stays the same across every endpoint it went to.
 */
final class Envelope
{
    /**
     * @return array<string, mixed>
     */
    public static function single(WebhookDelivery $delivery, WebhookEvent $event, WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $event->id,
            'type' => $delivery->type,
            'created_at' => $event->occurred_at->toIso8601String(),
            'delivery_id' => $delivery->id,
            'attempt' => $delivery->attempt,
            'sequence' => $delivery->sequence,
            'scope' => $delivery->scope()->toArray(),
            'api_version' => $endpoint->api_version,
            'data' => Json::map($event->payload),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function batch(string $batchId, array $items): array
    {
        return [
            'batch' => true,
            'delivery_id' => $batchId,
            'count' => count($items),
            'deliveries' => $items,
        ];
    }
}
