<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;

/**
 * One wire format: how a delivery, or a batch of them, becomes a request body.
 *
 * An endpoint names the format it wants (`cbox` unless it says otherwise) and
 * the deliverer asks that format for the bytes. Whatever it returns is signed
 * as it is and sent as it is, so a format must return the final body, not a
 * structure for someone else to encode.
 *
 * A format must be deterministic for a given input and must not reach outside
 * its arguments: it runs on every attempt, inside a worker.
 */
interface EnvelopeFormat
{
    public function single(EnvelopeItem $item): EncodedEnvelope;

    /**
     * One body for every item of a batch, in sequence order.
     *
     * @param  non-empty-list<EnvelopeItem>  $items
     */
    public function batch(string $batchId, array $items): EncodedEnvelope;
}
