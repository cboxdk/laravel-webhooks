---
title: Sequencing and ordering
description: What the per-endpoint sequence number promises, what it does not, and how a receiver uses it.
weight: 28
---

# Sequencing and ordering

Every delivery carries a `sequence`: a gapless, strictly increasing integer per
endpoint, assigned at fan-out under a row lock. It is in the envelope and in
`X-Webhook-Sequence`.

## What it promises

- The order events were *published* for that endpoint.
- Gaplessness: if you have 41 and 43, 42 exists and is either on its way, retrying,
  exhausted or cancelled. The management API and the commands can tell you which.
- Stability across retries and replays: a replayed event gets a *new* delivery with a
  *new* sequence; the original keeps its number.

## What it does not promise

Arrival order. Retries, the breaker and parallel workers mean 43 can arrive before 42.
Delivery is at-least-once, so 42 can arrive twice (same delivery id, different
`attempt`).

## On the receiving side

Use the delivery id to deduplicate and the sequence to order. The package's own
receiver does both: the inbound ledger is unique per (endpoint name, delivery id), and
the gap detector remembers the last sequence per (endpoint, scope) in the cache and
fires `WebhookGapDetected` when a number is skipped. A gap is a signal to reconcile
(fetch the state, or ask the sender for a replay), not an error.

## Batches

A batch's items are in sequence order and `X-Webhook-Sequence` is `first-last`.
