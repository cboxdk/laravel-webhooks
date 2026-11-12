---
title: Cbox Webhooks
description: The webhook engine for Laravel. Publish domain events, deliver them signed with retries, a circuit breaker and batching, receive other systems' webhooks, and watch it all.
weight: 1
---

# Cbox Webhooks

Webhooks for Laravel: an outbox-style engine that turns your domain events into signed
HTTP deliveries, keeps delivering through outages, and receives the webhooks other
systems send you with the same rigour.

## The mental model

```
publish(event) ──► events table ──► one delivery per matching endpoint ──► queue job
                                                                            │
                     ┌──────────────────────────────────────────────────────┘
                     ▼
            claim the attempt (conditional update)
                     ▼
            admit? paused / disabled / breaker / rate limit
                     ▼
            sign + POST  ──► settle: delivered | failed (retry at …) | exhausted
```

- **An event is published once** and stored. Every endpoint whose subscription covers
  the event type gets its own **delivery**, with its own per-endpoint **sequence**
  number and its own retry history. One failing receiver never slows another.
- **An attempt is a row.** Every HTTP request the engine makes is recorded with the
  outcome, the status code, the duration and an excerpt of the response, so support can
  answer "what did we send and what did you say" without logs.
- **Everything is scoped.** Endpoints, events and deliveries belong to a
  [`Scope`](core-concepts/scopes.md) (a tenant, optionally inside a partition). A
  lookup from another scope answers `null`, never 403.
- **Failures are classified, not counted.** A 5xx, a timeout and a connection error
  count against the endpoint's health; a 429 honours `Retry-After`; a 410 disables the
  endpoint; a URL the SSRF guard now refuses is a policy retry that blames nobody.
- **Receiving is the mirror image.** The `webhooks.receive` middleware verifies the
  signature, parses the envelope, deduplicates by delivery id in a ledger and hands
  each item to your handlers, on a queue or inline.

## Sections

- [Quickstart](quickstart.md) — publish your first event and see it land
- [Requirements](requirements.md) — PHP, Laravel, extensions, dependencies
- [Getting started](getting-started/_index.md) — installation, the testing traits, upgrading
- [Core concepts](core-concepts/_index.md) — architecture, scopes, the catalog, the delivery lifecycle, retries, the breaker, batching, ordering, the envelope, monitoring
- [Cookbook](cookbook/_index.md) — publish domain events, expose the management API, receive webhooks, replay, rotate secrets, health checks, telemetry, erasure, paging through history
- [Extension points](extension-points/_index.md) — contracts, models, the scope resolver, metrics, signature schemes, a catalog and retention per scope, envelope formats
- [Security](security/_index.md) — threat model, SSRF, signing, standards
- [Configuration](configuration/_index.md) — every key in `config/webhooks.php`

## What this package is not

- Not a UI. It ships a JSON management API and console commands; the pages are yours.
- Not exactly-once. Delivery is at-least-once with a per-endpoint sequence and a
  delivery id, so a receiver can be idempotent; the engine never promises a request
  arrives exactly once.
- Not per-item acknowledgement inside a batch. A batch succeeds or fails as a whole.
- Not a hosted service, not mTLS, not OAuth-authenticated receivers. Signing is HMAC
  through `cboxdk/laravel-webhook-signature`.
