---
title: Envelope
description: The JSON body and the headers a receiver gets, for a single delivery and for a batch.
weight: 29
---

# Envelope

## Single delivery

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

## Batch

```json
{
  "batch": true,
  "delivery_id": "01JBF…",              // the batch id
  "count": 3,
  "deliveries": [ { …single envelope… }, { … }, { … } ]
}
```

## Headers

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
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
