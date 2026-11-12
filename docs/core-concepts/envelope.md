---
title: Envelope
description: The body and the headers a receiver gets, in the cbox envelope or as CloudEvents 1.0, for a single delivery and for a batch.
weight: 29
---

# Envelope

The body is produced by the endpoint's *envelope format*. Every endpoint has one:
`cbox` unless it chose another when it was registered or updated.

| Format | Body | `Content-Type` |
| --- | --- | --- |
| `cbox` (default) | the package's own JSON envelope, below | `application/json` |
| `cloudevents` | CloudEvents 1.0, structured mode, JSON | `application/cloudevents+json; charset=utf-8` |

A host can register more; see [Envelope formats](../extension-points/envelope-formats.md).
Whatever the format, the bytes it returns are signed as they are and sent as they are,
and the headers below are the same.

## The cbox envelope

### Single delivery

```json
{
  "id": "01JBF…",                       // the event id: the same across endpoints and replays
  "type": "invoice.paid",
  "created_at": "2026-10-30T12:00:00+00:00",   // when the event occurred
  "delivery_id": "01JBF…",              // unique per (event, endpoint); deduplicate on this
  "attempt": 1,
  "sequence": 41,                       // per endpoint, gapless
  "scope": {"partition": "eu", "key": "org_1"},
  "api_version": "2026-10-01",          // the endpoint's api_version, or null
  "data": { … }                         // your payload, untouched
}
```

### Batch

```json
{
  "batch": true,
  "delivery_id": "01JBF…",              // the batch id
  "count": 3,
  "deliveries": [ { …single envelope… }, { … }, { … } ]
}
```

## CloudEvents

```php
new NewEndpoint('https://customer.example/hooks', ['invoice.*'], envelope: 'cloudevents');
```

```json
{
  "specversion": "1.0",
  "id": "01JBF…",                              // the event id
  "source": "https://app.example",             // webhooks.cloudevents.source
  "type": "invoice.paid",
  "time": "2026-10-30T12:00:00Z",              // when the event occurred, UTC
  "subject": "eu/org_1",                       // the owner: partition/key, or the key alone
  "datacontenttype": "application/json",
  "deliveryid": "01JBF…",                      // extension: unique per (event, endpoint)
  "sequence": "41",                            // extension: per endpoint, gapless
  "sequencetype": "Integer",
  "attempt": 1,                                // extension
  "apiversion": "2026-10-01",                  // extension: only when the endpoint has one
  "data": { … }                                // your payload, always an object
}
```

- `source` + `id` identify the event, as the specification asks: a retry and a replay
  of the same event carry the same `id`. To tell deliveries apart (a replay you want to
  process again), use `deliveryid`.
- `source` is `webhooks.cloudevents.source`, or `app.url` when that is not set. Set it:
  it is how a receiver knows who produced the event.
- `subject` is left out for an event with no owner (a global scope).
- A batching endpoint gets the CloudEvents batch format: a JSON array of events with
  `Content-Type: application/cloudevents-batch+json; charset=utf-8`. The batch id is in
  `X-Webhook-Delivery`.
- This is structured mode only. There are no `ce-*` headers.

## Headers

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json`, or what the envelope format declares |
| `User-Agent` | `Cbox-Webhooks/1.0 (+https://github.com/cboxdk/laravel-webhooks)` unless `webhooks.http.user_agent` is set |
| `X-Webhook-Event` | the type, or `batch` |
| `X-Webhook-Delivery` | the delivery id, or the batch id |
| `X-Webhook-Sequence` | the sequence, or `first-last` |
| `X-Webhook-Attempt` | the attempt number |
| `Idempotency-Key` | the delivery id |
| `X-Request-Id` | a fresh id per attempt, echoed in the attempt row |
| `traceparent` | with telemetry: the delivery span's context |
| signature headers | per scheme, see [Signing](../security/signing.md) |
| static headers | the endpoint's own, e.g. `Authorization` |

Reserved headers cannot be set as static headers: `Host`, `Content-Length`,
`Content-Type`, `Transfer-Encoding`, `Connection`, `User-Agent`, `Idempotency-Key`,
`traceparent`, `tracestate`, the `X-Webhook-*` set, and every signature header of the
supported schemes.

## Versioning

`api_version` is a label the endpoint's owner chooses. It travels in the envelope so a
receiver can branch; the engine does not transform payloads. Shape your payload per
version at publish time if you must support several.

## Ping

`Endpoints::ping()` sends a `webhook.ping` event through the same path (sequence,
attempt row, signature), synchronously, and returns the `AttemptResult`. Register
`webhook.ping` in your catalog if it is strict.
