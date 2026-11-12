---
title: User agent and fixed headers
description: Set the user agent and the headers every request carries, for all endpoints through config or per endpoint and scope through RequestProfiles.
weight: 59
---

# User agent and fixed headers

Two levels.

**One answer for everyone**, in config:

```php
'http' => [
    'user_agent' => 'Acme-Webhooks/1.0 (+https://acme.example/docs/webhooks)',
    'headers' => ['X-Sender' => 'acme'],
],
```

**An answer per endpoint**, when one installation sends for several products or
customers and each should see the sender it knows. Bind
`Cbox\Webhooks\Contracts\RequestProfiles`:

```php
use Cbox\Webhooks\Contracts\RequestProfiles;
use Cbox\Webhooks\ValueObjects\{Endpoint, RequestProfile};

final class BrandProfiles implements RequestProfiles
{
    public function for(Endpoint $endpoint): RequestProfile
    {
        // By scope, or by something you stored in the endpoint's metadata.
        return match ($endpoint->metadata['product'] ?? null) {
            'engage' => new RequestProfile('Cbox-Engage-Webhooks/1.0', ['X-Product' => 'engage']),
            default => new RequestProfile,      // the configured user agent, no extra headers
        };
    }
}

$this->app->bind(RequestProfiles::class, BrandProfiles::class);
```

`RequestProfile(?string $userAgent = null, array $headers = [])`. A null user agent
keeps `webhooks.http.user_agent`, or the package's own when that is null too.

## Precedence

Headers are written in this order, and a later one replaces an earlier one of the same
name:

1. the profile's headers (the host),
2. the endpoint's own static headers (its owner, for example `Authorization`),
3. the signature headers, `X-Webhook-*`, `Idempotency-Key` and `traceparent` (the engine).

So a profile cannot replace a signature or transport header, and an endpoint's owner
can override a host header. `Content-Type` comes from the envelope format and
`User-Agent` from the profile; neither can be set as a header.

A custom binding replaces the default, so it no longer reads `webhooks.http.headers`
unless you do so yourself.

`for()` runs once per attempt in the worker. Keep it to a lookup.
