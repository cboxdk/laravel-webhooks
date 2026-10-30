---
title: Models
description: Subclass a shipped model to add a relation or a global scope; the package keeps the schema.
weight: 52
---

# Models

`webhooks.models` names the five Eloquent classes:

```php
'models' => [
    'endpoint' => App\Webhooks\Endpoint::class,   // extends Cbox\Webhooks\Models\WebhookEndpoint
    'event' => …, 'delivery' => …, 'attempt' => …, 'inbound' => …,
],
```

A class that does not extend the shipped model is refused at boot. The package reads
every model through `Support\Models`, so your subclass is used everywhere: queries,
relations, the sweep.

What a subclass is for: a relation to your `Organization` (`scope_key` is the id), a
global scope for your admin UI, accessors. What it is not for: changing columns or
casts the engine relies on. The table name comes from `webhooks.table_prefix`; a
subclass should not set `$table`.

Secrets are `encrypted` casts; reading `$endpoint->secret` decrypts. Do not log it.
