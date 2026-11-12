---
title: Upgrading
description: What changes between releases, which migrations run, and the steps a host takes.
weight: 13
---

# Upgrading

Migrations are additive: a release adds new migration files and never edits a
published one. With `webhooks.run_migrations` on (the default), `php artisan migrate`
applies them. If you published the migrations, publish again
(`vendor:publish --tag=webhooks-migrations`) to get the new files.

## 0.1 to 0.2

Composer's caret is narrow below 1.0: `^0.1` does not allow 0.2. Change the constraint
to `^0.2` (or `^0.1 || ^0.2` while you roll out).

### Behaviour that changed

- **An owner endpoint without a partition no longer receives events published with
  one.** `Scope::of('org_1')` and `Scope::of('org_1', 'eu')` are different scopes, as
  [Scopes](../core-concepts/scopes.md) always said; 0.1 delivered across them. If you
  use one shape on both sides, nothing changes. If you registered endpoints without a
  partition and publish with one, publish without it, or move the endpoints
  (`UPDATE webhook_endpoints SET partition = ? WHERE scope_key = ? AND partition IS NULL`).

- **`Contracts\Deliveries` has a new method, `paginate()`.** Calling code is not
  affected. If you implement or decorate `Deliveries` yourself, add it (a decorator
  forwards it to the inner instance).

### Migrations

| Migration | Adds |
| --- | --- |
| `2026_11_12_000000_add_scope_to_webhook_attempts` | `partition` and `scope_key` on `webhook_attempts`, filled from each attempt's delivery, and an index on `(partition, scope_key, started_at)` |

The backfill is one `UPDATE` per column over the attempts table. On a table with many
millions of rows, run the migration off-peak, or prune first (`webhooks:prune`).

During a rolling deploy a worker still on 0.1 writes attempts without the scope
columns. They are still erased with their delivery; to make them listable by scope, run
the migration's two `UPDATE` statements once more after the last old worker is gone.
