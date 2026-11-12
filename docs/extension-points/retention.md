---
title: Retention per scope
description: Bind RetentionPolicies so each owner's deliveries, attempts and events are kept for its own number of days, with the config as the fallback.
weight: 57
---

# Retention per scope

`webhooks.retention` is one set of days for everyone. A host whose customers keep
their history for different lengths (by plan, by contract) binds
`Cbox\Webhooks\Contracts\RetentionPolicies`:

```php
use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;

final class PlanRetention implements RetentionPolicies
{
    public function for(Scope $scope): Retention
    {
        $plan = Plan::forOrganization($scope->partition);

        return match ($plan?->tier) {
            'free' => Retention::days(3),
            'scale' => new Retention(deliveredDays: 90, failedDays: 180, eventsDays: 90),
            default => new Retention,          // the configured defaults
        };
    }
}

// AppServiceProvider::register(), after the package's provider
$this->app->bind(RetentionPolicies::class, PlanRetention::class);
```

`Retention` has three fields, in days: `deliveredDays`, `failedDays` (exhausted and
cancelled deliveries) and `eventsDays` (the replay horizon). A field left `null` takes
the value from `webhooks.retention`, so `new Retention` is "the default" and
`new Retention(eventsDays: 7)` changes one thing. Nothing goes below one day.

## What the pruner does with it

`webhooks:prune` (daily) looks up every scope that has a delivery or an event, asks
`for()` once per scope, and prunes that scope by the answer, in chunks, on the
per-scope indexes. Attempts go with their deliveries.

With the default binding every scope has the same answer, and the pruner works on
whole tables as it did in 0.1. Binding your own switches it to scope by scope; expect
the run to take longer in proportion to the number of scopes with rows.

The inbound ledger has no scope and keeps `webhooks.retention.inbound_days`.

A wide scope (a partition-wide or global event) is asked for like any other; return
`new Retention` if you have no opinion.

## Testing

```php
$this->fakeRetentionPolicies()->set(Scope::of('shop', 'org_a'), Retention::days(7));
```

A scope nothing was set for keeps the configured default.
