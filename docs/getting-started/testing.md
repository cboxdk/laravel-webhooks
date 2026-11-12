---
title: Testing
description: The InteractsWithWebhooks trait, FakeWebhooks, and the helpers for testing a receiver. The package's own suite uses nothing else.
weight: 12
---

# Testing

Add the trait to your test case. It composes `cboxdk/laravel-ssrf`'s and
`cboxdk/laravel-webhook-signature`'s testing traits, so DNS and signatures are faked
through the same helpers the package uses itself.

```php
use Cbox\Webhooks\Testing\InteractsWithWebhooks;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithWebhooks;
}
```

## Two levels

**Fake the publisher** when the test is about *your* code deciding to publish:

```php
$fake = $this->fakeWebhooks();        // or Webhooks::fake()

$this->invoices->settle($invoice);

$fake->assertPublished('invoice.paid', fn (OutboundEvent $e) => $e->payload['id'] === $invoice->id);
$fake->assertNotPublished('invoice.voided');
$fake->assertPublishedCount(1);
```

Nothing is stored and nothing is sent. The fake honours idempotency keys the way the
real publisher does.

**Run the engine** when the test is about what reaches the receiver. The database, the
queue (sync) and the HTTP client are real; only DNS and the network are faked.

```php
$registered = $this->registerWebhookEndpoint(Scope::of('org_1'), 'https://customer.example/hooks', ['invoice.*']);
// DNS for customer.example now resolves to a public address; the HTTP fake answers 200.

Webhooks::publish(new OutboundEvent('invoice.paid', Scope::of('org_1'), ['id' => 'inv_1']));

$this->assertWebhookDelivered('https://customer.example/hooks', 'invoice.paid', function (Request $request, array $envelope) {
    return $request->hasHeader('X-Cbox-Signature') && $envelope['data']['id'] === 'inv_1';
});
$this->assertWebhookDeliveredTimes('https://customer.example/hooks', 1);
$this->assertWebhookNotDelivered('https://other.example/hooks');
```

Shape the receiver's answers with `Http::fake()` as usual: `Http::fake(['customer.example/*' => Http::response('', 500)])`
before publishing. `recordedWebhookRequests($url)` returns `[Request, envelope]` pairs,
with batch envelopes flattened to their items.

`registerWebhookEndpoint()` takes the delivery policy too (`retrySchedule`,
`timeoutSeconds`, `followRedirects`, `transform`). For an endpoint with a transform, bind
the fake transformer first: `$this->fakePayloadTransformer()->define('pick', fn (array
$input) => ['id' => $input['id']])`. An expression you do not define passes `data`
through unchanged.

Time is `CarbonImmutable::setTestNow()`; retries are due when the clock says so. Run
the sweep with `deliverPendingWebhooks()` to push whatever is due:

```php
Http::fake(['customer.example/*' => Http::sequence()->push('', 503)->push('', 200)]);
Webhooks::publish(…);                                     // first attempt fails, retry in 10s

CarbonImmutable::setTestNow(now()->addSeconds(10));
$report = $this->deliverPendingWebhooks();                // SweepReport
expect($report->retriesQueued)->toBe(1);
```

Set `webhooks.retry.jitter` to `0` in your test environment to make the schedule exact.

Signatures are timestamped by the signature package's `Cbox\WebhookSignature\Contracts\Clock`,
not by Carbon. A frozen Carbon therefore never makes an outbound signature "from the
future" for a receiver in the same process; if a test needs the signature timestamp to
follow the frozen time as well, bind a `Clock` that reads `CarbonImmutable::now()`.

Other helpers: `defineWebhookEvent($type, $group)` registers a catalog entry for the
test; `fakeEventCatalogs()` gives every scope its own catalog (see
[A catalog per scope](../extension-points/event-catalogs.md)); `fakeDnsForUrl($url)`
fakes DNS for a URL you register some other way.

## Testing a receiver

```php
Route::post('/hooks/billing', ReceiveWebhookController::class)->middleware('webhooks.receive:billing');

$this->fakeWebhookReceiver('billing', 'shh');            // endpoint name + secret(s), processes inline
$this->onWebhook('invoice.paid', ApplyPayment::class);

$this->postWebhook('/hooks/billing', 'billing', 'invoice.paid', ['id' => 'inv_1'], deliveryId: 'dlv_1')
    ->assertStatus(202);

$this->assertWebhookHandled('invoice.paid', fn (IncomingWebhook $w) => $w->data['id'] === 'inv_1');
```

`postWebhookBatch()` posts a batch envelope; `webhookEnvelope()` builds one to sign
yourself with the signature package's `postSignedWebhook()`. Unsigned posts come from
`postUnsignedWebhook()` and are refused with 401.

## Assertions on value objects

Every value object a contract returns (`Endpoint`, `Delivery`, `AttemptResult`,
`Overview`, …) can be built with no arguments, so a test double of a contract can return
`new Delivery` without reflection.
