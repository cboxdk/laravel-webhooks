---
title: Cookbook
description: Task-oriented recipes: publishing, the management API, receiving, replay, rotation, health, telemetry, erasure.
weight: 40
---

# Cookbook

- [Publish domain events](publish-domain-events.md) — from a Laravel event or a service, idempotently, inside a transaction
- [Management API](management-api.md) — expose endpoints, deliveries and the catalog to your customers
- [Receiving](receiving.md) — accept another system's webhooks with the middleware and handlers
- [Replay and resend](replay-and-resend.md) — a customer's outage, a bug of yours, a single delivery
- [Secret rotation](secret-rotation.md) — rotate with an overlap, change the scheme
- [Health check](health-check.md) — a readiness probe from the Monitor
- [Telemetry and metrics](telemetry-metrics.md) — what to graph and alert on
- [Erasure](erasure.md) — forget a scope
- [Deliveries, attempts and events](history.md) — page through a scope's history with a cursor
