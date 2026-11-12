<?php

declare(strict_types=1);
use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Models\WebhookInboundDelivery;

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Every table the package owns carries this prefix. The package runs its own
    | migrations unless "run_migrations" is off, in which case publish them
    | (`vendor:publish --tag=webhooks-migrations`) and run them yourself.
    | Migrations are additive: a shipped one is never edited.
    |
    */

    'table_prefix' => 'webhook_',

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Subclass to add a global scope or a relation of your own; the package still
    | owns the schema. A class that does not extend the shipped model is refused.
    |
    */

    'models' => [
        'endpoint' => WebhookEndpoint::class,
        'event' => WebhookEvent::class,
        'delivery' => WebhookDelivery::class,
        'attempt' => WebhookAttempt::class,
        'inbound' => WebhookInboundDelivery::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | A partition-wide or global endpoint receives every owner's events. Off by
    | default: the tenant boundary must not be crossable by a null that arrived
    | from a variable. Turn it on only in an app whose operator plane registers
    | such endpoints on purpose, through registerPartitionWide()/registerGlobal().
    |
    */

    'allow_wide_endpoints' => false,

    /*
    |--------------------------------------------------------------------------
    | Endpoint URLs
    |--------------------------------------------------------------------------
    |
    | Endpoint URLs are validated by cboxdk/laravel-ssrf at registration and
    | pinned again right before every send. Only https is accepted unless
    | "allow_http" is on (a local test receiver).
    |
    */

    'allow_http' => false,

    /*
    |--------------------------------------------------------------------------
    | Catalog
    |--------------------------------------------------------------------------
    |
    | Publishing a type the catalog does not know throws UnknownEventType when
    | strict. Off, it logs a warning and publishes anyway.
    |
    */

    'catalog' => [
        'strict' => true,
    ],

    'max_payload_bytes' => 262_144,

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where the delivery jobs run. Null uses the application's defaults.
    |
    */

    'queue' => [
        'connection' => null,
        'queue' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'connect_timeout' => 5,
        'timeout' => 10,
        'user_agent' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | The delay before each retry, in seconds, with full jitter of +/- "jitter".
    | Eleven entries give twelve attempts over roughly two days. A failure past
    | the last entry exhausts the delivery.
    |
    */

    'retry' => [
        'schedule' => [10, 30, 120, 600, 1800, 3600, 7200, 14400, 28800, 43200, 86400],
        'jitter' => 0.2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After "failure_threshold" consecutive failures an endpoint is left alone for
    | "cooldown_seconds", doubling per consecutive trip up to "max_cooldown_seconds".
    | A single probe is admitted when the cooldown elapses.
    |
    */

    'breaker' => [
        'failure_threshold' => 5,
        'cooldown_seconds' => 300,
        'max_cooldown_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-disable
    |--------------------------------------------------------------------------
    |
    | An endpoint that has not succeeded since its first failure this long ago
    | is disabled (reason "unreachable") and its pending deliveries cancelled.
    |
    */

    'disable_after_seconds' => 259_200,

    /*
    |--------------------------------------------------------------------------
    | Sweep
    |--------------------------------------------------------------------------
    |
    | A pending or in-flight delivery older than "stranded_after_seconds" is
    | presumed lost (queue flushed, worker died) and re-queued by the sweep, up
    | to "sweep_limit" rows per tick.
    |
    | "sweep_fairness" decides who gets those rows when more are due than the
    | limit. "none" is oldest first across every scope: simple, and right when
    | one application owns every endpoint. "scope" takes them round-robin over
    | (partition, key) and "partition" round-robin over the partition, so one
    | owner's backlog cannot delay everyone else's retries; pick the one that
    | names your tenant.
    |
    */

    'stranded_after_seconds' => 900,

    'sweep_limit' => 200,

    'sweep_fairness' => 'none',

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    |
    | The bounds an endpoint's batch_size and batch_window_seconds must stay in.
    |
    */

    'batch' => [
        'max_size' => 100,
        'max_window_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret rotation
    |--------------------------------------------------------------------------
    |
    | How long the previous secret stays valid after a rotation, by default.
    |
    */

    'secret_rotation' => [
        'overlap_seconds' => 86_400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    |
    | The sweep runs every minute and the prune daily when these are on. Turn
    | one off to drive the command from your own schedule.
    |
    */

    'schedule' => [
        'sweep' => true,
        'prune' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | "events_days" is the replay horizon: an event older than that cannot be
    | replayed because it is gone.
    |
    */

    'retention' => [
        'delivered_days' => 30,
        'failed_days' => 90,
        'events_days' => 30,
        'inbound_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Receiving
    |--------------------------------------------------------------------------
    |
    | Inbound webhooks are processed on a queue unless "inline" is on. Gap
    | detection remembers the last sequence per (endpoint, scope) in the cache
    | and fires WebhookGapDetected when one is skipped.
    |
    */

    'receive' => [
        'queue' => null,
        'connection' => null,
        'inline' => false,
        'detect_gaps' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | The Metrics implementation. Null picks the telemetry adapter when
    | cboxdk/laravel-telemetry is installed and "telemetry.enabled" is on, and
    | NullMetrics otherwise. Name a class to bind your own adapter.
    |
    */

    'metrics' => null,

    'telemetry' => [
        'enabled' => true,
    ],

];
