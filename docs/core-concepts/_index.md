---
title: Core concepts
description: How the engine is built and why: scopes, the catalog, the delivery lifecycle, retries, the breaker, batching, ordering, the envelope, monitoring.
weight: 20
---

# Core concepts

- [Architecture](architecture.md) — the modules, the contracts, the tables
- [Scopes](scopes.md) — tenancy: owner, partition-wide and global endpoints
- [Catalog](catalog.md) — the event types you offer, wildcards, legacy types
- [Delivery lifecycle](delivery-lifecycle.md) — pending to delivered, attempt claims, what each outcome does
- [Retries and backoff](retries-and-backoff.md) — the schedule, jitter, `Retry-After`, exhaustion
- [Circuit breaker](circuit-breaker.md) — tripping, the probe, doubling cooldowns, auto-disable
- [Batching](batching.md) — one request for many deliveries
- [Sequencing and ordering](sequencing-and-ordering.md) — what the sequence number promises
- [Envelope](envelope.md) — the JSON body and headers a receiver gets
- [Monitoring](monitoring.md) — the Monitor contract, metrics and the sweep
- [Delivery policy](delivery-policy.md) — an endpoint's own retry schedule, timeout, redirects and sealed headers
