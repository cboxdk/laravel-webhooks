---
title: Envelope formats
description: Register your own wire format and let an endpoint choose it. The format returns the final bytes, and those bytes are what is signed and sent.
weight: 58
---

# Envelope formats

An endpoint is sent in the envelope format it names: `cbox` (the default) or
`cloudevents`, both described in [Envelope](../core-concepts/envelope.md). A host adds
its own by implementing `Cbox\Webhooks\Contracts\EnvelopeFormat` and registering it:

```php
use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\ValueObjects\{EncodedEnvelope, EnvelopeItem};

final class LegacyV1Envelope implements EnvelopeFormat
{
    public function single(EnvelopeItem $item): EncodedEnvelope
    {
        return new EncodedEnvelope(json_encode([
            'event' => $item->type,
            'uuid' => $item->deliveryId,
            'payload' => $item->data,
        ], JSON_THROW_ON_ERROR));
    }

    public function batch(string $batchId, array $items): EncodedEnvelope
    {
        return new EncodedEnvelope(json_encode([
            'batch' => $batchId,
            'events' => array_map(fn (EnvelopeItem $i) => ['event' => $i->type, 'uuid' => $i->deliveryId, 'payload' => $i->data], $items),
        ], JSON_THROW_ON_ERROR));
    }
}

// AppServiceProvider::boot()
Webhooks::envelopes()->register('legacy-v1', new LegacyV1Envelope);
```

Then an endpoint asks for it: `new NewEndpoint($url, $types, envelope: 'legacy-v1')`,
`new EndpointChanges(envelope: 'legacy-v1')`, or `"envelope": "legacy-v1"` through the
management API (the form requests accept every registered name).

## The contract

```php
public function single(EnvelopeItem $item): EncodedEnvelope;
public function batch(string $batchId, array $items): EncodedEnvelope;   // non-empty-list<EnvelopeItem>
```

`EnvelopeItem` carries `eventId`, `type`, `occurredAt`, `deliveryId`, `attempt`,
`sequence`, `scope`, `data`, `apiVersion` and `endpointId`: values, never a model.
`EncodedEnvelope` is the `body` (a string) and its `contentType` (default
`application/json`).

## Rules

- **Return the final bytes.** The deliverer signs `body` and sends `body`. Nothing
  encodes it again, so the signature a receiver checks is over exactly what it reads.
- **Be deterministic and self-contained.** A format runs on every attempt inside a
  worker. Do not query, do not call out.
- **Keep what a receiver needs to be idempotent.** Put `deliveryId` (and, if order
  matters, `sequence`) in the body; they are also in the `X-Webhook-Delivery` and
  `X-Webhook-Sequence` headers whatever the format.
- **Batching is the format's job too.** An endpoint with `batch_size > 1` calls
  `batch()`.
- **Do not retire a name endpoints still use.** A delivery for an endpoint whose format
  is no longer registered throws `InvalidEndpoint` in the worker: the job fails, the
  delivery stays claimed, and the sweep retries it after `stranded_after_seconds`, so
  nothing is lost and nothing is sent in the wrong shape. Register the format again or
  move the endpoints.

Registering under an existing name replaces that format, including `cbox` and
`cloudevents`.

The package's own receiver (`webhooks.receive`) parses the `cbox` envelope only.
