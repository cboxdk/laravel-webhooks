---
title: Deliveries, attempts and events
description: Page through a scope's deliveries, HTTP attempts and recorded events with a cursor, and look an event up by id.
weight: 49
---

# Deliveries, attempts and events

Three logs, each read inside one scope and each paginated the same way.

```php
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Facades\Webhooks;
use Cbox\Webhooks\ValueObjects\{DeliveryFilter, Scope};

$scope = Scope::of($project->id, $organization->id);
$filter = new DeliveryFilter(status: DeliveryStatus::Failed, limit: 50);

$page = Webhooks::deliveries()->paginate($scope, $filter);

foreach ($page->items as $delivery) { /* ValueObjects\Delivery */ }

if ($page->hasMore()) {
    $next = Webhooks::deliveries()->paginate($scope, $filter, $page->nextCursor);
}
```

| Call | Returns | Ordered by | Filter |
| --- | --- | --- | --- |
| `Webhooks::deliveries()->paginate($scope, DeliveryFilter, ?string $cursor)` | `Page<Delivery>` | `created_at` | `endpointId`, `eventId`, `status`, `type`, `since`, `until`, `limit` |
| `Webhooks::attempts()->paginate($scope, AttemptFilter, ?string $cursor)` | `Page<Attempt>` | `started_at` | `endpointId`, `deliveryId`, `outcome`, `since`, `until`, `limit` |
| `Webhooks::events()->paginate($scope, EventFilter, ?string $cursor)` | `Page<RecordedEvent>` | `occurred_at` | `type`, `since`, `until`, `limit` |
| `Webhooks::events()->find($scope, $id)` | `?RecordedEvent` | | |

The contracts are `Contracts\Deliveries`, `Contracts\AttemptLog` and
`Contracts\EventLog`.

## The page

`ValueObjects\Page` has `items` (a list), `nextCursor` (a string, or null on the last
page), `hasMore()` and `isEmpty()`. `limit` on the filter is the page size, from 1 to
1000, default 100. Everything is newest first; `since` and `until` are inclusive.

## The cursor

- It is opaque. Store it or hand it to your client as it is; do not build one.
- It names a position (the last row of the page it came from), not a page number. Rows
  written while a caller pages through are neither skipped nor repeated: new rows land
  before the first page and the cursor points past them.
- Use it with the same scope and the same filter it came from.
- It is not an authorization. Every query is filtered by the scope you pass, so a
  cursor from another scope returns nothing of that scope.
- A string this package did not issue throws `Exceptions\InvalidCursor`; the management
  API answers `422` with `errors.cursor`.

There is no total count and no "page 7": both need a scan the keyset avoids.

## What is still there

Deliveries and attempts live until `webhooks:prune` removes them, events until their
replay horizon. See [Erasure](erasure.md) for the windows. `Deliveries::list()` remains
for the newest N rows with no continuation.

## Events of one delivery, deliveries of one event

`Delivery::$eventId` is the event's id: `Webhooks::events()->find($scope, $delivery->eventId)`
is the payload that was sent. The other way round, `new DeliveryFilter(eventId: $event->id)`
lists where one event went.
