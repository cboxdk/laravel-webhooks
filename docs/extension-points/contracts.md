---
title: Contracts
description: The interfaces in Cbox\Webhooks\Contracts, what each returns, and how to replace or decorate one.
weight: 51
---

# Contracts

Everything the engine does is an interface in `Cbox\Webhooks\Contracts`, bound in
`WebhooksServiceProvider::register()`. Rebind in your own provider after the package's:

```php
$this->app->bind(Deliverer::class, fn ($app) => new LoggingDeliverer($app->make(HttpDeliverer::class)));
```

| Contract | Returns | Notes |
| --- | --- | --- |
| `Publisher` | `PublishedEvent` | `publish`, `publishMany` |
| `Endpoints` | `RegisteredEndpoint`, `Endpoint`, `RotatedSecret`, `AttemptResult`, `EndpointHealth`, `int` | every method takes the caller's `Scope` first |
| `Deliveries` | `Delivery`, `list<Delivery>`, `Page<Delivery>`, `bool` | `find` includes attempts; `paginate` takes a cursor |
| `AttemptLog` | `Page<Attempt>` | a scope's HTTP attempts, paginated |
| `EventLog` | `RecordedEvent`, `Page<RecordedEvent>` | `find`, `paginate` |
| `Deliverer` | `?AttemptResult` | `null` means the claim failed: nothing was sent |
| `BatchFlusher` | `int` | batches formed |
| `EventCatalog` | `EventDefinition`, `list<string>`, `bool` | `register`, `has`, `get`, `all`, `expand`, `covers` |
| `EventCatalogs` | `EventCatalog` | `for(Scope)`; the shared catalog for every scope by default. See [A catalog per scope](event-catalogs.md) |
| `Monitor` | `Overview`, `EndpointStats`, `bool` | |
| `Sweeper`, `Pruner` | `SweepReport`, `PruneReport` | |
| `Erasure` | `int` | |
| `Receivers` | handlers | `on`, `handlersFor`, `hasHandlersFor` |
| `ScopeResolver` | `Scope` | not bound by default |
| `Metrics`, `Tracing` | void / mixed | no-ops by default |

The implementations are `final`; decorate, do not extend. Every value object a contract
returns has a meaningful zero-argument constructor, so a test double can `return new
Overview;`.

## Events instead of contracts

For reacting rather than replacing, listen to the Laravel events in
`Cbox\Webhooks\Events`. They carry the same value objects.

## The facade

`Webhooks::fake()` swaps only the `Publisher`. Everything else stays real, so a test
that fakes publishing can still register endpoints.
