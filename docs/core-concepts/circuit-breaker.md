---
title: Circuit breaker
description: Consecutive failures trip an endpoint; one probe is admitted after a cooldown that doubles per trip; a long-dead endpoint is disabled.
weight: 26
---

# Circuit breaker

Per endpoint, on the endpoint row. Nothing is cached, so every worker sees the same
state.

## Tripping

Each failure that counts (5xx, 4xx other than 410 and 429, timeout, connection error)
increments `consecutive_failures`. At `webhooks.breaker.failure_threshold` (default
5) the endpoint trips: `circuit_opened_at` is set, `trip_count` goes up, and
`EndpointTripped` fires with the time the circuit closes.

While open, a delivery that reaches admission is parked at `next_attempt_at =
closes_at` with `last_error = breaker_open`. No request, no attempt row, no retry
budget spent.

## The probe

When the cooldown elapses the first worker to arrive re-arms the circuit with a
conditional update and becomes the probe; the others wait for the new closing time.
If the probe succeeds the breaker closes, all health counters reset, and
`EndpointRecovered` fires; the sweep then pushes the rest. If it fails the circuit
re-opens with a longer cooldown.

## Cooldown

`cooldown_seconds × 2^(trip_count − 1)`, capped at `max_cooldown_seconds`. Defaults
are 5 minutes doubling to 1 hour. Any success resets `trip_count`.

## Auto-disable

`first_failure_at` is set on the first failure after a success and cleared by any
success. When it is older than `webhooks.disable_after_seconds` (default 3 days) the
endpoint is disabled with reason `unreachable`, its pending and failed deliveries are
cancelled, and `EndpointDisabled` fires. This is checked after each failed attempt and
by the sweep, so an endpoint nothing tries any more is still disabled on time.

`Endpoints::resume()` clears the breaker, the disabled reason and the health counters,
and re-queues the backlog in sequence order. A 410 disables with reason `gone`
immediately; resume works the same way.

## What the breaker ignores

A 429, a blocked URL, a rate-limit wait and a paused endpoint. None of them says the
receiver is down.
