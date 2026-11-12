---
title: Delivery policy
description: What each endpoint may set for itself — its own retry schedule, timeout, redirect following and headers — and the bounds on each.
weight: 31
---

# Delivery policy

Every endpoint is delivered with the configured defaults unless it says otherwise. Four
things can be set per endpoint, on `NewEndpoint` at registration and on
`EndpointChanges` later. Null always means "the configured default", so an endpoint
registered before 0.3 delivers exactly as it did.

```php
Webhooks::endpoints()->register(Scope::of('org_1'), new NewEndpoint(
    url: 'https://hooks.example.com/cbox',
    eventTypes: ['invoice.*'],
    retrySchedule: [10, 60, 300, 900, 1800],   // seconds; [] = one try, no retries
    timeoutSeconds: 5,                         // the whole request, redirects included
    followRedirects: true,                     // 307 and 308 only, at most 3 hops
    headers: ['Authorization' => 'Bearer ek_…'],
));
```

| Field | Column | Default | Bounds |
| --- | --- | --- | --- |
| `retrySchedule` (`list<int>`) | `retry_schedule` json | `retry.schedule` | at most 20 entries, each 1 to 604 800 seconds |
| `timeoutSeconds` | `timeout_seconds` | `http.timeout` | 1 to 120, or to `http.max_endpoint_timeout` when lower |
| `followRedirects` | `follow_redirects` | `false` | — |
| `headers` | `headers` json, values sealed | none | 10 headers, names are tokens, values up to 1000 characters on one line |
| `transform` | `transform` text | none | 4000 characters, validated by the bound [transformer](../extension-points/payload-transformer.md) |

Anything out of bounds is refused with `InvalidEndpoint`, at registration and on update.
To go back to the default, pass `clearRetrySchedule`, `clearTimeout` or `clearTransform`
on `EndpointChanges` (null there means "leave as is").

## Retry schedule

An endpoint's schedule replaces `retry.schedule` for that endpoint only: the delay after
attempt *n* is entry *n*, and a failure past the last entry exhausts the delivery. An
empty schedule is one attempt and no retries. The jitter is always the configured
`retry.jitter`. A receiver's `Retry-After` is capped at the largest entry, and once the
schedule is spent a 429 exhausts the delivery like any other failure. See
[Retries and backoff](retries-and-backoff.md).

## Timeout

The endpoint's timeout replaces `http.timeout` and covers the whole request, every
redirect hop included. A delivery job's own timeout is sized from the connect timeout
plus the higher of `http.timeout` and `http.max_endpoint_timeout`, so lower
`max_endpoint_timeout` when your endpoints never need two minutes, and keep your queue's
`retry_after` above the job timeout.

## Redirects

Off by default, and then any 3xx is a failed attempt, as before. When an endpoint
follows redirects:

- Only **307** and **308** are followed: the two that keep the method and the body. The
  same POST, the same bytes and the same signature headers go to the next URL.
- **301, 302 and 303** fail the attempt with `redirect_refused`. In practice clients turn
  them into a GET without a body, which is not a delivery.
- At most **3 hops**. A fourth redirect fails with `too_many_redirects`.
- Each `Location` is resolved against the URL that sent it and must pass the same
  [SSRF guard](../security/ssrf.md) as the endpoint's own URL, https included when
  https is required. The connection to each hop is pinned to the addresses the guard
  validated for that hop. A redirect to a private address is a `blocked_url` attempt.

## Headers

An endpoint's headers are where an owner puts a bearer token or an API key, so their
values are sealed at rest with the bound
[`SecretEncrypter`](../extension-points/secret-encrypter.md), like the signing secret,
and opened only to send and in the `Endpoint` value object. `Endpoint::toArray()` (and so
the management API) shows them masked: `Bearer…`. `Endpoint::maskedHeaders()` gives the
same map.

These names are refused, case-insensitively: `Host`, `Content-Length`, `Content-Type`,
`Content-Encoding`, `Transfer-Encoding`, `Connection`, `User-Agent`, `Idempotency-Key`,
`traceparent`, `tracestate`, every header a signature scheme sends, and any name
starting with `webhook-`, `x-webhook-`, `x-cbox-` or `ce-`. A name must be an HTTP token
(RFC 9110), a value must not contain CR, LF or NUL.
