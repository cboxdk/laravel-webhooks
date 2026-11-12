# Cbox Webhooks

Webhooks for Laravel. Publish a domain event once; the engine turns it into one signed
delivery per subscribed endpoint and keeps delivering through outages: a retry
schedule with jitter, a per-endpoint circuit breaker, `Retry-After`, rate limits,
batching, a sweep that rescues what a dead worker left behind. On the other side, a
middleware that verifies, deduplicates and dispatches the webhooks other systems send
you. Every request is a row you can show to support.

- **Outbox, not fire-and-forget.** Events, deliveries and attempts are tables. Fan-out
  joins your transaction; jobs go out after commit; an attempt is claimed with a
  conditional update so two workers never send twice.
- **Tenancy as a value.** Every endpoint, event and delivery belongs to a `Scope`.
  Another scope's id answers `null`. Wide endpoints exist only when the operator opts in.
- **Receivers are attacker input.** URLs pass `cboxdk/laravel-ssrf` at registration
  and are pinned again right before each send; redirects are refused unless an
  endpoint opts in to 307/308, and then every hop is guarded and pinned the same way;
  reserved headers are refused; secrets and header values are encrypted at rest (under
  `APP_KEY` or a key you choose) and secrets are shown once.
- **Per-endpoint delivery policy.** Its own retry schedule and timeout, redirect
  following, headers, the `cbox`, CloudEvents or `raw` envelope, and an optional
  transform through an expression language you bind. Replays can name events, skip
  what already arrived, be paced, and be counted before they are sent.
- **Signed by `cboxdk/laravel-webhook-signature`**: `cbox` or `standard-webhooks`,
  fresh timestamp per attempt, rotation with an overlap.
- **Observable.** Seventeen typed Laravel events, a `Monitor` with the numbers a health
  check wants, and metrics, spans and `traceparent` propagation when
  `cboxdk/laravel-telemetry` is present.

Documentation: [docs/index.md](docs/index.md). Start with the
[quickstart](docs/quickstart.md).

## Install

```bash
composer require cboxdk/laravel-webhooks
php artisan migrate
```

Queue workers and `schedule:run` must be running. `php artisan vendor:publish
--tag=webhooks-config` for the rest.

## Publish

```php
use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\ValueObjects\{NewEndpoint, OutboundEvent, Scope};

// once, in a provider: the types you offer
Webhooks::catalog()->register(new EventDefinition('invoice.paid', 'invoice', 'Invoice paid', 'An invoice was settled.'));

// when a customer gives you a URL: the secret is shown once
$registered = Webhooks::endpoints()->register(Scope::of($org->id), new NewEndpoint('https://customer.example/hooks', ['invoice.*']));

// when it happens
Webhooks::publish(new OutboundEvent('invoice.paid', Scope::of($org->id), ['id' => $invoice->id], "invoice.paid:{$invoice->id}"));
```

The receiver gets a JSON envelope with the event, a per-endpoint gapless sequence, a
delivery id to deduplicate on, and the signature headers. A 2xx settles it; a 429 waits
for `Retry-After`; a 410 disables the endpoint; anything else retries on the schedule
for about two days before the delivery is exhausted and `DeliveryExhausted` fires.

## Receive

```php
Route::post('/hooks/billing', ReceiveWebhookController::class)->middleware('webhooks.receive:billing');

Webhooks::receivers()->on('invoice.paid', ApplyPayment::class);
```

Verify, parse, deduplicate by delivery id, detect sequence gaps, run the handlers on a
queue (or inline), answer 202.

## Operate

```bash
php artisan webhooks:status --endpoints
php artisan webhooks:deliveries --status=failed --since="-1 hour"
php artisan webhooks:retry {delivery}
php artisan webhooks:replay {endpoint} --since="2026-10-29"
```

Or expose the management API (`Routes::register()`) behind your own auth and a
`ScopeResolver`, and let customers register, pause, rotate, ping and replay themselves.

## Test

```php
use Cbox\Webhooks\Testing\InteractsWithWebhooks;

$this->registerWebhookEndpoint(Scope::of('org_1'), 'https://customer.example/hooks', ['invoice.*']);
$this->artisan('invoices:settle', ['id' => 'inv_1']);
$this->assertWebhookDelivered('https://customer.example/hooks', 'invoice.paid');
```

DNS and HTTP are faked by the trait; `Webhooks::fake()` swaps the publisher when the
test is about your code, not the wire. The package's own suite uses the same helpers.

## Requirements

PHP 8.4+, Laravel 12 or 13. See [docs/requirements.md](docs/requirements.md).

## Security

See [SECURITY.md](SECURITY.md) and [docs/security](docs/security/_index.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
