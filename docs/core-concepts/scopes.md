---
title: Scopes
description: The tenant boundary as a value: owner scopes, partition-wide and global endpoints, and why a null never widens a query.
weight: 22
---

# Scopes

A `Scope` is `(partition, key)`. The key is the owner (an organization, a workspace, a
customer); the partition is an optional outer grouping (an environment, a region, a
product). Both are opaque strings to the engine.

```php
Scope::of('org_1');                 // owner, no partition
Scope::of('org_1', 'eu');           // owner inside a partition
Scope::partitionWide('eu');         // every owner in the partition
Scope::global();                    // everyone
```

`Scope::of()` refuses an empty key. The wide forms exist only through their named
constructors, and the registry refuses to store them unless
`webhooks.allow_wide_endpoints` is on. Pass a wide scope to `register()` and you get
`InvalidScope`; the only way to register a wide endpoint is `registerPartitionWide()`
or `registerGlobal()`. A null that arrived from a variable cannot cross a tenant
boundary by accident.

## Matching

An event published in `Scope::of('org_1', 'eu')` is delivered to:

- endpoints in exactly `('eu', 'org_1')`,
- partition-wide endpoints in `eu` (when allowed),
- global endpoints (when allowed).

An endpoint in `Scope::of('org_1')` (no partition) does *not* match: the partition is
part of the identity, and `null` means "none", not "any".

## Lookups

Every `Endpoints` and `Deliveries` method takes the caller's scope first and answers
`null` (or `false`, or `0`, or `[]`) for an id that exists in another scope. There is no
403: the id is not yours, so it does not exist. The management API maps that to 404.

The two places that see every scope are the sweep and the operator-facing `Monitor`
with no scope given. Both are named in the docblocks; nothing else queries wide.

## The management API

The API never takes a scope from the request body or the URL. You bind a
[`ScopeResolver`](../extension-points/scope-resolver.md) that derives it from the
authenticated caller; with none bound the API refuses to serve.
