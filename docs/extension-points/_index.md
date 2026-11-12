---
title: Extension points
description: Replace a contract, subclass a model, bind the scope resolver, plug in metrics, add a signature scheme, give each scope its own catalog and its own retention.
weight: 50
---

# Extension points

- [Contracts](contracts.md) — every seam and how to rebind it
- [Models](models.md) — subclassing the five models
- [Scope resolver](scope-resolver.md) — the caller's scope for the management API
- [Metrics](metrics.md) — your own metrics backend
- [Signature schemes](signature-schemes.md) — schemes beyond `cbox` and `standard-webhooks`
- [A catalog per scope](event-catalogs.md) — each customer of a multi-tenant host with its own event types
- [Retention per scope](retention.md) — how long each owner's history is kept
- [Envelope formats](envelope-formats.md) — your own wire format, chosen per endpoint
