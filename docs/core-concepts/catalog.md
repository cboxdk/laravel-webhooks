---
title: Catalog
description: The event types you offer, how subscriptions expand, and how a type is retired without breaking anyone.
weight: 23
---

# Catalog

The catalog is the list of event types a receiver can subscribe to. It is strict by
default (`webhooks.catalog.strict`): publishing an unknown type throws
`UnknownEventType`, and so does subscribing to one. Off, publishing logs a warning and
goes ahead; subscribing is always validated.

```php
new EventDefinition(
    type: 'invoice.paid',           // group.name
    group: 'invoice',
    label: 'Invoice paid',
    description: 'An invoice was settled in full.',
    example: ['id' => 'inv_1'],     // shown by the API and the markdown
    supersededBy: null,             // set to retire the type
    offered: true,                  // false hides it from wildcards and the API
);
```

## Subscriptions

An endpoint subscribes to a list of patterns:

- `invoice.paid` — exactly that type.
- `invoice.*` — every *offered* type in the group, including ones you add later.
- `*` — every offered type.

`EventCatalog::expand()` resolves patterns to concrete types (for display);
`covers()` answers whether a subscription includes a type at delivery time, so a type
registered after the endpoint is matched by its wildcard.

## Retiring a type

Set `supersededBy` on the old definition and register the new one. The old type is
*legacy*: it no longer matches wildcards, so new subscribers get only the new type, while
an endpoint that named it explicitly keeps receiving it until its owner moves. The
markdown and the API show `legacy → invoice.settled`.

A type with `offered: false` is internal: deliverable to an explicit subscription (for
your own integrations) but invisible to `*` and to the catalog endpoint.

## One catalog, or one per scope

Everything above describes one catalog. The engine does not read it directly: it asks
`Contracts\EventCatalogs` for the catalog of the scope at hand, and the default answers
this shared one for every scope. A multi-tenant host binds its own and gives each
customer its own types; see [A catalog per scope](../extension-points/event-catalogs.md).

## Showing it

- `GET /catalog` in the management API returns the definitions of the caller's catalog.
- `php artisan webhooks:catalog` prints a table; `--markdown` a table per group for
  your documentation; `--scope=partition/key` the catalog of one scope.
