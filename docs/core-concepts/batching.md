---
title: Batching
description: One request carrying many deliveries, by size or by window; how batches are formed, signed, retried and acknowledged.
weight: 27
---

# Batching

An endpoint with `batch_size > 1` receives batches: up to `batch_size` deliveries in
one request, sent when the batch is full or when `batch_window_seconds` has elapsed
since the oldest pending delivery. Bounds are `webhooks.batch.max_size` (100) and
`max_window_seconds` (300).

## Forming a batch

Deliveries for a batching endpoint are created `pending` without a per-delivery job.
Publishing pushes `FlushEndpointBatches`: immediately when the pending count reaches
`batch_size`, otherwise delayed by the window. The sweep also flushes every minute, so a
lost flush job costs at most a minute.

The flusher claims due deliveries in sequence order with a conditional update
(`batch_id IS NULL`), stamps a shared `batch_id`, moves them to `in_flight` and pushes
one `DeliverBatch`. Two flushers running at once form disjoint batches.

## The request

One envelope with `batch: true`, the batch id as `delivery_id`, a `count`, and
`deliveries`, each a full single envelope. Headers: `X-Webhook-Event: batch`,
`X-Webhook-Delivery: <batch id>`, `X-Webhook-Sequence: first-last`,
`X-Webhook-Attempt: <highest attempt in the batch>`. The signature covers the whole body;
with `standard-webhooks` the `webhook-id` is the batch id.

## Acknowledgement

The batch succeeds or fails as a whole. A 2xx delivers every item; anything else fails
every item with the same attempt number and the same `next_attempt_at`, and one attempt
row per delivery (sharing the `batch_id`) so each delivery's history stays complete.
There is no per-item acknowledgement: a receiver that cannot process one item should
still answer 2xx and deal with that item itself, or reject the whole batch and get it
again.

## Retries

When the batch is due again the flusher regroups whatever is due for that endpoint,
which may be a different set: a retrying item and newly published ones can travel
together. Ordering is kept: items are always claimed in sequence order.

## Pause, resume, disable

Pause leaves the rows pending; resume flushes. Disable cancels them.
