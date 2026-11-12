---
title: A catalog per scope
description: Bind EventCatalogs so each customer of a multi-tenant host has its own event types, validated on publish, subscribe, fan-out and in the API.
weight: 56
---

# A catalog per scope

By default there is one catalog: the `EventCatalog` singleton your provider registers
into, in force for every scope. That is right for an application that publishes its own
events.

A host that runs the engine *for* its customers, where each customer defines its own
event types, binds `Cbox\Webhooks\Contracts\EventCatalogs`:

```php
use Cbox\Webhooks\Catalog\InMemoryEventCatalog;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\ValueObjects\Scope;

final class CustomerCatalogs implements EventCatalogs
{
    /** @var array<string, EventCatalog> */
    private array $loaded = [];

    public function for(Scope $scope): EventCatalog
    {
        return $this->loaded[$scope->toString()] ??= $this->load($scope);
    }

    private function load(Scope $scope): EventCatalog
    {
        $catalog = new InMemoryEventCatalog;

        // Your own table of event types, filtered by the scope. No rows: an empty catalog.
        foreach (EventType::forScope($scope)->get() as $type) {
            $catalog->register($type->toDefinition());
        }

        return $catalog;
    }
}

// AppServiceProvider::register(), after the package's provider
$this->app->scoped(EventCatalogs::class, CustomerCatalogs::class);
```

`scoped()` gives one instance per request or job, so the per-scope cache above lives
exactly that long and a type a customer adds is seen by the next request.

## Where the engine asks

| When | Scope it asks for |
| --- | --- |
| `Publisher::publish()` | the event's scope |
| `Endpoints::register()` / `update()` | the endpoint's scope |
| `Endpoints::matching()` (fan-out) | the event's scope |
| `Endpoints::replay()` | the caller's scope |
| Management API: `GET /catalog`, the `event_types` validation | the scope your `ScopeResolver` returns |
| `webhooks:catalog --scope=partition/key` | the scope named |

So a type one customer offers is unknown in another customer's scope: publishing it
there throws `UnknownEventType` (when `webhooks.catalog.strict` is on, the default) and
subscribing to it is refused.

## Rules for an implementation

- **Deny by default.** For a scope you do not know, return an empty catalog. Never fall
  back to another scope's, and fall back to the shared `EventCatalog` only if you mean
  every customer to be offered those types.
- **Be cheap.** `for()` is called on every publish and every fan-out; cache per scope.
- **Wide endpoints.** A partition-wide or global endpoint is registered against the
  catalog of *its* scope (`Scope::partitionWide(...)`, `Scope::global()`), and at
  fan-out its patterns are read against the *event's* scope's catalog. An exact type
  name always matches; `group.*` and `*` follow the event's catalog.

`Webhooks::catalog()` with no argument is still the shared catalog;
`Webhooks::catalog($scope)` is the one in force for a scope.

## Testing

`$this->fakeEventCatalogs()` (from `InteractsWithWebhooks`) binds an in-memory
`FakeEventCatalogs` and returns it:

```php
$catalogs = $this->fakeEventCatalogs()
    ->define(Scope::of('shop', 'org_a'), new EventDefinition('order.shipped', 'order', 'Order shipped', '…'))
    ->define(Scope::of('desk', 'org_b'), new EventDefinition('ticket.opened', 'ticket', 'Ticket opened', '…'));
```

A scope nothing was defined for offers nothing.
