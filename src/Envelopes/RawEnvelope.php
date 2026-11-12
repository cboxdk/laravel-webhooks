<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Envelopes;

use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;

/**
 * The payload and nothing around it: the body is the event's published data
 * as JSON, or the endpoint's transform result when it has one. For a receiver
 * that expects its own shape (an ingest API, a chat webhook) and would reject
 * an envelope.
 *
 * Nothing about the delivery is lost: the signature headers sign these bytes
 * as with any envelope, the event type travels in `X-Webhook-Event`, the
 * delivery id in `X-Webhook-Delivery` and `Idempotency-Key` (and `webhook-id`
 * under Standard Webhooks), the attempt and the sequence in their own headers.
 *
 * A batch is a JSON array of the payloads, in sequence order.
 */
final class RawEnvelope implements EnvelopeFormat
{
    public const string NAME = 'raw';

    public function single(EnvelopeItem $item): EncodedEnvelope
    {
        return new EncodedEnvelope(Json::encodeValue($item->payload()));
    }

    public function batch(string $batchId, array $items): EncodedEnvelope
    {
        return new EncodedEnvelope('['.implode(',', array_map(static fn (EnvelopeItem $item): string => Json::encodeValue($item->payload()), $items)).']');
    }
}
