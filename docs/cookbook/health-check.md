---
title: Health check
description: A readiness or liveness answer from the Monitor, and what the thresholds mean.
weight: 46
---

# Health check

```php
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\ValueObjects\Thresholds;

Route::get('/health/webhooks', function (Monitor $monitor) {
    $healthy = $monitor->isHealthy(new Thresholds(
        maxDueOverdue: 100,                // retries more than a minute late
        maxOldestPendingAgeSeconds: 600,   // a first attempt waiting ten minutes
        minSuccessRate24h: 0.9,            // null: ignore
        maxInFlight: null,
    ));

    return response()->json($monitor->overview()->toArray(), $healthy ? 200 : 503);
});
```

What each threshold catches:

- `dueOverdue` grows when the sweep is not running or workers are behind. It is the
  first number to alert on.
- `oldestPendingAgeSeconds` grows when the queue is not consumed at all.
- `successRate24h` drops when many receivers fail at once, which usually means the
  problem is on your side (a bad payload, a broken deploy).
- `inFlight` grows when workers die mid-request; the sweep rescues them after
  `stranded_after_seconds`, so a persistently high number means workers keep dying.

With `cboxdk/laravel-health` installed, wrap the same call in a check class.
