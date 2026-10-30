---
title: Quickstart
description: Install, define an event type, register an endpoint, publish, and watch the delivery land. Ten minutes.
weight: 2
---

# Quickstart

## 1. Install

```bash
composer require cboxdk/laravel-webhooks
php artisan migrate
```

The package runs its own migrations (five `webhook_*` tables). Queue workers and the
scheduler must be running: deliveries are queue jobs, and the sweep that rescues
anything stranded is scheduled every minute.

## 2. Describe the events you offer

The catalog is strict by default: an unknown type cannot be published or subscribed to.
Register your types in a service provider:

```php
use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Contracts\EventCatalog;

public function boot(EventCatalog $catalog): void
{
    $catalog->register(
        new EventDefinition('invoice.paid', 'invoice', 'Invoice paid', 'An invoice was settled in full.', example: ['id' => 'inv_1']),
        new EventDefinition('invoice.voided', 'invoice', 'Invoice voided', 'An invoice was cancelled before payment.'),
    );
}
```

`php artisan webhooks:catalog` prints it; `--markdown` prints the table for your docs.

## 3. Register an endpoint

Your customer gives you a URL. The secret is shown once and stored encrypted.

```php
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\ValueObjects\{NewEndpoint, Scope};

$registered = Webhooks::endpoints()->register(
    Scope::of($organization->id),
    new NewEndpoint(url: 'https://customer.example/hooks', eventTypes: ['invoice.*']),
);

$registered->secret;        // whsec_… or 64 hex chars, depending on the scheme; show it once
$registered->endpoint->id;  // a ULID
```

The URL is checked by `cboxdk/laravel-ssrf`: only public https hosts, and the address is
pinned again right before every send. Or expose the
[management API](cookbook/management-api.md) and let customers do this themselves.

## 4. Publish

```php
use Cbox\Webhooks\ValueObjects\OutboundEvent;

Webhooks::publish(new OutboundEvent(
    type: 'invoice.paid',
    scope: Scope::of($organization->id),
    payload: ['id' => $invoice->id, 'total' => $invoice->total],
    idempotencyKey: "invoice.paid:{$invoice->id}",
));
```

Publishing stores the event, creates one delivery per matching endpoint and pushes a
job. The second publish with the same idempotency key in the same scope returns the
first event's id with `duplicate: true` and creates nothing. Publish inside a database
transaction and the job waits for the commit.

## 5. See it land

```bash
php artisan webhooks:status --endpoints
php artisan webhooks:deliveries --status=failed --since="-1 hour"
```

The receiver gets a JSON envelope, an `X-Cbox-Signature` (or standard-webhooks) header,
`X-Webhook-Event`, `X-Webhook-Delivery`, `X-Webhook-Sequence`, `X-Webhook-Attempt` and
`Idempotency-Key`. A 2xx settles the delivery; anything else follows the
[retry schedule](core-concepts/retries-and-backoff.md).

## 6. Test it

```php
use Cbox\Webhooks\Testing\InteractsWithWebhooks;

uses(InteractsWithWebhooks::class);

it('tells the customer when an invoice is paid', function () {
    $this->registerWebhookEndpoint(Scope::of('org_1'), 'https://customer.example/hooks', ['invoice.*']);

    $this->artisan('invoices:settle', ['id' => 'inv_1']);

    $this->assertWebhookDelivered('https://customer.example/hooks', 'invoice.paid', fn ($request, array $envelope) => $envelope['data']['id'] === 'inv_1');
});
```

The trait fakes DNS and HTTP for you; see [Testing](getting-started/testing.md).
