---
title: Payload transformer
description: Reshape an event per endpoint with an expression language the host chooses, by binding Contracts\PayloadTransformer.
weight: 61
---

# Payload transformer

An endpoint may carry a `transform`: an expression that reshapes each event before it
is sent, so a receiver that wants its own shape (an ingest API, a chat webhook) can be
served without code in between. The package ships **no expression language**. The
default binding, `Transforms\UnavailablePayloadTransformer`, refuses every expression
with "no payload transformer is configured", so transforms are off until the host binds
one.

```php
namespace App\Webhooks;

use Cbox\Webhooks\Contracts\PayloadTransformer;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\TransformFailed;
use JmesPath\Env as JmesPath;
use JmesPath\SyntaxErrorException;
use Throwable;

final class JmesPathTransformer implements PayloadTransformer
{
    public function validate(string $expression): void
    {
        try {
            JmesPath::search($expression, []);
        } catch (SyntaxErrorException $e) {
            throw InvalidEndpoint::invalidTransform($e->getMessage());
        }
    }

    public function apply(string $expression, array $input): mixed
    {
        try {
            return JmesPath::search($expression, $input);
        } catch (Throwable $e) {
            throw new TransformFailed($e->getMessage());
        }
    }
}

// AppServiceProvider::register()
$this->app->bind(PayloadTransformer::class, JmesPathTransformer::class);
```

(JMESPath is an example of a language a host might choose; it is not a dependency of
this package.)

## The contract

- `validate(string $expression): void` runs on `register()` and `update()` when a
  transform is given, after a length check (1 to 4000 characters). Throw
  `InvalidEndpoint` with a message the owner can act on.
- `apply(string $expression, array $input): mixed` runs on every attempt, inside the
  worker. It must be deterministic and must not reach outside its arguments.

The input is the event:

```php
[
    'id' => 'evt_…',                         // the event id
    'type' => 'invoice.paid',
    'occurred_at' => '2026-10-30T12:00:00+00:00',
    'subject' => 'person_1',                 // OutboundEvent::$subject, or null
    'data' => [/* the published payload */],
]
```

The result replaces `data` in the `cbox` and `cloudevents` envelopes and is the whole
body in the [`raw`](../core-concepts/envelope.md#raw) envelope. For a batching endpoint
the transform runs per event.

## When it fails

A `TransformFailed` from `apply()`, a null result, or a result JSON cannot encode fails
that delivery **permanently**: one attempt is recorded with outcome `transform_failed`
and the message (cut to 1000 bytes), and the delivery is exhausted with reason
`transform_failed`. There is no retry, because the same event and expression fail the
same way every time, and the endpoint's breaker is not touched, because the receiver did
nothing wrong. In a batch only the failing event leaves; the rest are sent.

Any other exception from `apply()` is treated as a bug in the transformer: the job
fails, the delivery stays in flight, and the sweep's stranded rescue retries it later.
Wrap what your language throws in `TransformFailed`.

## Testing

`InteractsWithWebhooks::fakePayloadTransformer()` binds `Testing\FakePayloadTransformer`:
an expression you do not define passes `data` through unchanged, `define($expression,
fn (array $input) => …)` gives one a closure, `refuse($expression)` makes it fail
validation, and `inputs()` returns what it was given.
