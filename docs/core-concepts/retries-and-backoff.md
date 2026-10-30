---
title: Retries and backoff
description: The schedule, jitter, Retry-After handling, the cap, and when a delivery is exhausted.
weight: 25
---

# Retries and backoff

`webhooks.retry.schedule` lists the delay before each retry, in seconds. The default
is eleven entries, twelve attempts, about two days:

```
10s, 30s, 2m, 10m, 30m, 1h, 2h, 4h, 8h, 12h, 24h
```

After attempt *n* fails, the delivery waits `schedule[n-1]` seconds. A failure after
the last entry exhausts the delivery (`DeliveryExhausted` with reason
`retries_exhausted`). Shorten the list for a faster give-up; set it to `[]` for a single
attempt.

## Jitter

Each delay is multiplied by a random factor in `[1 - jitter, 1 + jitter]`
(`webhooks.retry.jitter`, default `0.2`). A thousand deliveries that failed together do
not retry together. Set it to `0` in tests.

## `Retry-After`

On a 429 the engine reads `Retry-After` as seconds or an HTTP date (RFC 9110) and
waits that long, capped at the largest entry in the schedule so a receiver cannot park a
delivery for a week. Without the header the schedule applies. A 429 never counts against
the endpoint's health: the receiver is alive and talking.

## What spends the budget

Only a real attempt. Waiting for the circuit breaker, for a rate-limit slot, or on a
paused endpoint hands the attempt counter back. A rescued stranded delivery keeps its
counter, because the request may well have gone out.

## Per-delivery, not per-event

Each delivery has its own counter and its own `next_attempt_at`. The same event can be
delivered to one endpoint on the first try and still be retrying against another.

## Batches

A batch fails as a whole; every delivery in it gets the same attempt number and the
same `next_attempt_at`, and the flusher groups them again when due. See
[Batching](batching.md).
