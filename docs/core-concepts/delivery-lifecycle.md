---
title: Delivery lifecycle
description: From pending to a terminal state: the attempt claim, admission, the send, and what every outcome does to the delivery and the endpoint.
weight: 24
---

# Delivery lifecycle

```
pending ──claim──► in_flight ──settle──► delivered
                      │                  failed (next_attempt_at)  ──claim──► in_flight …
                      │                  exhausted  (retries, gone, endpoint deleted)
                      └─ cancelled (endpoint disabled or deleted while waiting)
```

`DeliveryStatus` has six values. `delivered`, `exhausted` and `cancelled` are
terminal; `pending` and `failed` are claimable; `in_flight` belongs to a worker.

## 1. Fan-out

`Publisher::publish()` stores the event and, inside the same transaction, creates one
delivery per endpoint whose subscription covers the type. Each takes the endpoint's next
sequence number under a row lock. Active endpoints get a job; a paused endpoint gets
the row and no job (its backlog waits); a disabled endpoint gets nothing.

## 2. Claim

The job calls `Deliverer::deliver($deliveryId, $expectedAttempt)`. The claim is one
conditional update: the row must still be at `$expectedAttempt` and claimable. A second
worker with the same job, or a sweep that re-pushed an already-finished delivery, gets
`null` and does nothing.

## 3. Admission

Before any network, the engine checks, in order:

| Condition | Result |
| --- | --- |
| endpoint deleted | exhausted, `endpoint_deleted` |
| endpoint paused | parked: back to pending, attempt handed back |
| endpoint disabled | cancelled |
| breaker open and no probe slot | failed with `next_attempt_at = closes_at`, no attempt row, attempt handed back |
| per-minute rate limit reached | failed until the next slot, an attempt row with outcome `rate_limited`, attempt handed back |

"Handed back" means the attempt counter is decremented again: waiting on the breaker
or a rate limit spends no retry budget.

## 4. Send

The envelope is built, signed with a fresh timestamp, and posted with the endpoint's
static headers plus `X-Webhook-Event`, `X-Webhook-Delivery`, `X-Webhook-Sequence`,
`X-Webhook-Attempt`, `Idempotency-Key` (the delivery id) and, with telemetry, a
`traceparent`. Redirects are not followed. The URL is re-resolved and pinned by
`cboxdk/laravel-ssrf` right before the request.

## 5. Settlement

One transaction writes the delivery, the attempt, and the endpoint's health, then fires
the events.

| Outcome | Delivery | Endpoint health | Event |
| --- | --- | --- | --- |
| 2xx | delivered | reset (breaker closes if open) | `DeliverySucceeded`, `EndpointRecovered` |
| 410 Gone | exhausted, `gone` | disabled, reason `gone`; backlog cancelled | `DeliveryExhausted`, `EndpointDisabled` |
| 429 | failed at `Retry-After` (capped) or the schedule | untouched | `DeliveryFailed` |
| other 4xx, 5xx | failed on the schedule | failure counted | `DeliveryFailed`, maybe `EndpointTripped` |
| timeout, connection error | failed on the schedule | failure counted | same |
| blocked URL (SSRF guard) | failed on the schedule | untouched | `DeliveryFailed` |
| past the last schedule entry | exhausted, `retries_exhausted` | failure counted | `DeliveryExhausted` |

A 408 counts like any other 4xx: a receiver that times out reading the request is not
healthy. A 3xx is a failure too, since redirects are never followed.

`DeliveryAttempted` fires for every outcome that made a request.

## Retry by hand

`Deliveries::retry($scope, $id)` takes a `failed` or `exhausted` delivery, makes it due
now and pushes it. A delivered or cancelled one answers `false`. `webhooks:retry` and
`POST /deliveries/{id}/retry` do the same.

## Sweep

Jobs get lost: a queue is flushed, a worker dies mid-request. The sweep, every minute,
takes a `pending` delivery that has not moved for `stranded_after_seconds`, or an
`in_flight` one older than that, and re-queues it (the attempt it was on is kept, so a
request that *did* go out still counts). See [Monitoring](monitoring.md).
