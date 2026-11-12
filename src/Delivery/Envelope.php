<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Envelopes\CboxEnvelope;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;

/**
 * From the stored rows to what an envelope format works with, and the `cbox`
 * shape as arrays for code that builds one by hand (the testing trait does).
 * The wire format itself is a {@see EnvelopeFormat},
 * chosen per endpoint.
 */
final class Envelope
{
    public static function item(WebhookDelivery $delivery, WebhookEvent $event, WebhookEndpoint $endpoint): EnvelopeItem
    {
        return new EnvelopeItem(
            eventId: $event->id,
            type: $delivery->type,
            occurredAt: $event->occurred_at,
            deliveryId: $delivery->id,
            attempt: $delivery->attempt,
            sequence: $delivery->sequence,
            scope: $delivery->scope(),
            data: Json::map($event->payload),
            apiVersion: $endpoint->api_version,
            endpointId: $endpoint->id,
        );
    }

    /**
     * The `cbox` envelope of one delivery.
     *
     * @return array<string, mixed>
     */
    public static function single(WebhookDelivery $delivery, WebhookEvent $event, WebhookEndpoint $endpoint): array
    {
        return CboxEnvelope::item(self::item($delivery, $event, $endpoint));
    }

    /**
     * The `cbox` batch envelope around already-built items.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function batch(string $batchId, array $items): array
    {
        return CboxEnvelope::wrap($batchId, $items);
    }
}
