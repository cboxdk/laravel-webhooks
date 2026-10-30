---
title: Scope resolver
description: Bind the ScopeResolver that turns an authenticated request into the caller's Scope for the management API.
weight: 53
---

# Scope resolver

```php
use Cbox\Webhooks\Contracts\ScopeResolver;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Http\Request;

final class OrganizationScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): Scope
    {
        $organization = $request->user()?->currentOrganization() ?? abort(403);

        return Scope::of($organization->id, $organization->region);
    }
}

// AppServiceProvider
$this->app->bind(ScopeResolver::class, OrganizationScopeResolver::class);
```

The resolver is called once per request by the API controllers and nothing in the
request body, query or path can replace what it returns. Throw or abort when there is no
authenticated owner; never return a wide scope from a resolver, the API is for owners.

Without a binding the controllers throw `ScopeResolverMissing`. That is deliberate:
there is no default that would not leak.
