---
title: Management API
description: Expose endpoints, deliveries and the catalog to your customers: routes, the scope resolver, and the JSON shapes.
weight: 42
---

# Management API

The package ships controllers and a route registrar, not routes. You decide the prefix,
the middleware and the scope.

```php
// routes/api.php
use Cbox\Webhooks\Http\Routes;

Routes::register($router, [
    'prefix' => 'v1/webhooks',
    'middleware' => ['auth:sanctum', 'throttle:api'],
    'as' => 'webhooks.',
]);
```

Bind a [`ScopeResolver`](../extension-points/scope-resolver.md) that derives the
caller's scope from the authenticated request. Without one every request throws
`ScopeResolverMissing`: there is no default that could be safe.

## Routes

| Method | Path | Does |
| --- | --- | --- |
| GET | `/catalog` | offered event types |
| GET | `/endpoints` | the caller's endpoints |
| POST | `/endpoints` | register; 201 with the secret, once |
| GET | `/endpoints/{id}` | one endpoint with health |
| PATCH | `/endpoints/{id}` | url, description, event_types, batch_size, batch_window_seconds, rate_limit_per_minute (null clears), headers, api_version (null clears), metadata |
| DELETE | `/endpoints/{id}` | 204; backlog cancelled |
| POST | `/endpoints/{id}/pause`, `/resume` | the endpoint |
| POST | `/endpoints/{id}/rotate-secret` | `overlap_seconds` optional; the new secret, once |
| POST | `/endpoints/{id}/ping` | synchronous; the `AttemptResult` |
| POST | `/endpoints/{id}/replay` | `since` required, `until`, `types[]`, `limit`; `{replayed: n}` |
| GET | `/endpoints/{id}/deliveries` | `status`, `type`, `since`, `limit` |
| GET | `/endpoints/{id}/stats` | `hours` (default 24) |
| GET | `/deliveries` | the caller's deliveries, a page at a time: `endpoint_id`, `event_id`, `status`, `type`, `since`, `until`, `limit`, `cursor` |
| GET | `/deliveries/{id}` | a delivery with its attempts |
| POST | `/deliveries/{id}/retry` | 202 `{queued: true}`; 422 unless failed or exhausted |
| GET | `/attempts` | the caller's HTTP attempts: `endpoint_id`, `delivery_id`, `outcome`, `since`, `until`, `limit`, `cursor` |
| GET | `/events` | the caller's recorded events: `type`, `since`, `until`, `limit`, `cursor` |
| GET | `/events/{id}` | one event with its payload |

Every response is `{"data": …}`. The three paginated listings add `"next_cursor"`: a
string to send back as `cursor` for the next page, or `null` on the last one (see
[Deliveries, attempts and events](history.md)). An id from another scope is `404 {"error":
"not_found"}`. Invalid input is `422 {"error": "validation", "errors": {field: [...]}}`,
including a URL the SSRF guard refuses, an unknown event type, a reserved header, a batch
size out of bounds.

## Registering

```http
POST /v1/webhooks/endpoints
{
  "url": "https://customer.example/hooks",
  "event_types": ["invoice.*"],
  "scheme": "standard-webhooks",
  "description": "ERP sync",
  "headers": {"Authorization": "Bearer …"},
  "rate_limit_per_minute": 120,
  "batch_size": 1,
  "api_version": "2026-10-01"
}
```

```json
{"data": {"id": "01JBF…", "url": "…", "status": "active", "scheme": "standard-webhooks",
          "event_types": ["invoice.*"], "health": {"tripped": false, …}, "secret": "whsec_…"}}
```

The `secret` key appears only here and on rotation. `GET` never returns it.

## Scope

Nothing in a request chooses the scope. Your resolver does, from the token, the session
or the host. Wide endpoints are never created through the API; they belong to your
operator plane and `registerPartitionWide()` / `registerGlobal()`.
