---
title: Receiving
description: Accept webhooks from another system: the middleware, the ledger, handlers, queued or inline processing, and gaps.
weight: 43
---

# Receiving

The receiving side verifies, deduplicates and dispatches. It understands the
[envelope](../core-concepts/envelope.md) this package sends, so two Cbox apps talk to
each other without glue; any sender that produces the same shape and signs with a
supported scheme works too.

## 1. Configure the inbound endpoint

Secrets and schemes for inbound endpoints live in `cboxdk/laravel-webhook-signature`'s
config, keyed by name:

```php
// config/webhook-signature.php
'endpoints' => [
    'billing' => ['scheme' => 'cbox', 'secrets' => [env('BILLING_WEBHOOK_SECRET'), env('BILLING_WEBHOOK_SECRET_PREVIOUS')]],
],
```

Several secrets let the sender rotate with an overlap.

## 2. Route it

```php
use Cbox\Webhooks\Http\Controllers\ReceiveWebhookController;

Route::post('/hooks/billing', ReceiveWebhookController::class)
    ->middleware('webhooks.receive:billing')
    ->withoutMiddleware(VerifyCsrfToken::class);
```

The middleware verifies the signature (401 `refused`, or 500 `misconfigured` so the
sender keeps retrying while you fix the secret), parses the envelope (400 `rejected`),
inserts each item into the ledger and drops the ones already seen (`WebhookDuplicate`
fires; a request with only duplicates answers 200 `duplicate` without reaching the
controller), checks sequence gaps, and hands the rest to the controller.

## 3. Handle

```php
// a service provider
public function boot(Receivers $receivers): void
{
    $receivers->on('invoice.paid', ApplyPayment::class);     // invokable or handle(IncomingWebhook)
    $receivers->on('invoice.*', RefreshInvoiceCache::class);
    $receivers->on('*', fn (IncomingWebhook $w) => Log::info('webhook', ['type' => $w->type]));
}
```

`IncomingWebhook` carries `id`, `type`, `occurredAt`, `deliveryId`, `sequence`,
`scope`, `apiVersion`, `data`, `endpointName`, `attempt` and the `raw` envelope.
Handlers run in registration order; a type with no handler is still recorded as
processed.

## Queued or inline

By default the controller answers 202 at once and a `ProcessInboundWebhook` job runs
the handlers (`webhooks.receive.queue` / `connection`). A handler that throws marks the
ledger row `failed`, fires `WebhookProcessingFailed` and rethrows, so the queue's own
retries apply.

With `webhooks.receive.inline` on, handlers run in the request: 202 when they all
succeed, 500 `failed` when one throws. The ledger row is `failed` either way, and the
sender's retry is a *duplicate* by delivery id: it is not reprocessed. Fix the handler,
then replay from the ledger or ask the sender to resend with a new delivery.

## Gaps

With `webhooks.receive.detect_gaps` on, the last sequence per (endpoint, scope) is
kept in the cache and `WebhookGapDetected` fires when a number is skipped. Reconcile
on it: the sender's management API can replay.

## The ledger

`webhook_inbound_deliveries` keeps every accepted item with its payload, status
(`received`, `processed`, `failed`), timestamps and error, for `retention.inbound_days`.
The unique key is (endpoint name, delivery id), so the same delivery id from two senders
is two rows.
