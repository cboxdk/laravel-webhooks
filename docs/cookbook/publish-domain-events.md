---
title: Publish domain events
description: Turn a Laravel event into a webhook, choose an idempotency key, publish inside the transaction, and publish many at once.
weight: 41
---

# Publish domain events

## From a listener

```php
final readonly class PublishInvoicePaid
{
    public function __construct(private Publisher $webhooks) {}

    public function handle(InvoicePaid $event): void
    {
        $this->webhooks->publish(new OutboundEvent(
            type: 'invoice.paid',
            scope: Scope::of($event->invoice->organization_id),
            payload: InvoiceResource::make($event->invoice)->resolve(),
            idempotencyKey: "invoice.paid:{$event->invoice->id}",
            occurredAt: $event->invoice->paid_at,
        ));
    }
}
```

Inject `Cbox\Webhooks\Contracts\Publisher`, or use the `Webhooks` facade. The payload
is yours; the engine only checks its size (`webhooks.max_payload_bytes`, 256 KiB by
default) and that the type exists in the catalog.

## Idempotency

The key is unique per scope. A retried listener, a replayed queue job or a double
click publishes once; the second call returns `PublishedEvent` with `duplicate: true`
and the original id. Make the key deterministic from the fact: `"invoice.paid:{id}"`,
not a UUID. Without a key every call is a new event.

## Inside a transaction

Publish inside `DB::transaction()` with the write that caused the event. The event and
delivery rows join your transaction; the jobs are dispatched after commit
(`ShouldQueueAfterCommit`), so a rollback publishes nothing and a worker never sees a
delivery before its event is visible.

## Many at once

```php
$this->webhooks->publishMany([$eventA, $eventB, $eventC]);   // one transaction
```

Returns a list of `PublishedEvent` in the same order.

## What a customer sees

`EventPublished` fires with the `OutboundEvent` and the `PublishedEvent` (ids and the
number of deliveries). The receiver gets the [envelope](../core-concepts/envelope.md).
