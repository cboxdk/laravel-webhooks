<?php

declare(strict_types=1);

namespace Cbox\Webhooks;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Webhooks\Catalog\InMemoryEventCatalog;
use Cbox\Webhooks\Catalog\SharedEventCatalogs;
use Cbox\Webhooks\Console\CatalogCommand;
use Cbox\Webhooks\Console\DeliveriesCommand;
use Cbox\Webhooks\Console\EndpointsCommand;
use Cbox\Webhooks\Console\PruneCommand;
use Cbox\Webhooks\Console\ReplayCommand;
use Cbox\Webhooks\Console\RetryCommand;
use Cbox\Webhooks\Console\StatusCommand;
use Cbox\Webhooks\Console\SweepCommand;
use Cbox\Webhooks\Contracts\AttemptLog;
use Cbox\Webhooks\Contracts\BatchFlusher;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\Erasure;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\EventLog;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Contracts\PayloadTransformer;
use Cbox\Webhooks\Contracts\Pruner;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\Contracts\RequestProfiles;
use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\Contracts\SecretEncrypter;
use Cbox\Webhooks\Contracts\SubjectErasure;
use Cbox\Webhooks\Contracts\Sweeper;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Delivery\ConfigRequestProfiles;
use Cbox\Webhooks\Delivery\DatabaseAttemptLog;
use Cbox\Webhooks\Delivery\DatabaseBatchFlusher;
use Cbox\Webhooks\Delivery\DatabaseDeliveries;
use Cbox\Webhooks\Delivery\HttpDeliverer;
use Cbox\Webhooks\Endpoints\ApplicationSecretEncrypter;
use Cbox\Webhooks\Endpoints\DatabaseEndpoints;
use Cbox\Webhooks\Envelopes\CboxEnvelope;
use Cbox\Webhooks\Envelopes\CloudEventsEnvelope;
use Cbox\Webhooks\Envelopes\InMemoryEnvelopeFormats;
use Cbox\Webhooks\Envelopes\RawEnvelope;
use Cbox\Webhooks\Housekeeping\ConfigRetentionPolicies;
use Cbox\Webhooks\Housekeeping\DatabaseErasure;
use Cbox\Webhooks\Housekeeping\DatabasePruner;
use Cbox\Webhooks\Housekeeping\DatabaseSweeper;
use Cbox\Webhooks\Http\Middleware\ReceiveWebhook;
use Cbox\Webhooks\Monitoring\DatabaseMonitor;
use Cbox\Webhooks\Monitoring\NullMetrics;
use Cbox\Webhooks\Monitoring\NullTracing;
use Cbox\Webhooks\Publishing\DatabaseEventLog;
use Cbox\Webhooks\Publishing\DatabasePublisher;
use Cbox\Webhooks\Receiving\InMemoryReceivers;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Telemetry\TelemetryHooks;
use Cbox\Webhooks\Telemetry\TelemetryMetrics;
use Cbox\Webhooks\Telemetry\TelemetryTracing;
use Cbox\Webhooks\Transforms\UnavailablePayloadTransformer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the engine. Every capability is a contract bound here; a host rebinds
 * or decorates the one it needs. The registries (catalog, receivers) are
 * singletons because the host fills them from its own providers; the metrics
 * and tracing seams pick the telemetry adapter only when that package is
 * present, so the library never requires it.
 */
class WebhooksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/webhooks.php', 'webhooks');

        $this->app->singleton(EventCatalog::class, InMemoryEventCatalog::class);
        $this->app->bind(EventCatalogs::class, SharedEventCatalogs::class);
        $this->app->singleton(Receivers::class, InMemoryReceivers::class);
        $this->app->singleton(WebhooksManager::class);

        $this->app->singleton(EnvelopeFormats::class, function (Application $app): EnvelopeFormats {
            $formats = new InMemoryEnvelopeFormats;
            $formats->register(CboxEnvelope::NAME, new CboxEnvelope);
            $formats->register(CloudEventsEnvelope::NAME, new CloudEventsEnvelope($this->cloudEventsSource($app)));
            $formats->register(RawEnvelope::NAME, new RawEnvelope);

            return $formats;
        });

        $this->app->bind(Publisher::class, DatabasePublisher::class);
        $this->app->bind(Endpoints::class, DatabaseEndpoints::class);
        $this->app->bind(Deliveries::class, DatabaseDeliveries::class);
        $this->app->bind(AttemptLog::class, DatabaseAttemptLog::class);
        $this->app->bind(EventLog::class, DatabaseEventLog::class);
        $this->app->bind(Deliverer::class, HttpDeliverer::class);
        $this->app->bind(BatchFlusher::class, DatabaseBatchFlusher::class);
        $this->app->bind(Monitor::class, DatabaseMonitor::class);
        $this->app->bind(Sweeper::class, DatabaseSweeper::class);
        $this->app->bind(Pruner::class, DatabasePruner::class);
        $this->app->bind(RetentionPolicies::class, ConfigRetentionPolicies::class);
        $this->app->bind(RequestProfiles::class, ConfigRequestProfiles::class);
        $this->app->bind(SecretEncrypter::class, ApplicationSecretEncrypter::class);
        $this->app->bind(PayloadTransformer::class, UnavailablePayloadTransformer::class);
        $this->app->bind(Erasure::class, DatabaseErasure::class);
        $this->app->bind(SubjectErasure::class, DatabaseErasure::class);

        $this->app->singleton(Metrics::class, function (Application $app): Metrics {
            $configured = $app->make(Config::class)->string('metrics');

            if ($configured !== null && class_exists($configured)) {
                $instance = $app->make($configured);

                if ($instance instanceof Metrics) {
                    return $instance;
                }
            }

            if ($this->telemetryAvailable($app)) {
                return $app->make(TelemetryMetrics::class);
            }

            return new NullMetrics;
        });

        $this->app->singleton(Tracing::class, function (Application $app): Tracing {
            return $this->telemetryAvailable($app) ? $app->make(TelemetryTracing::class) : new NullTracing;
        });
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/webhooks.php' => $this->app->configPath('webhooks.php')], 'webhooks-config');
        $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'webhooks-migrations');

        if ($this->app->make(Config::class)->bool('run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->app->make(Router::class)->aliasMiddleware('webhooks.receive', ReceiveWebhook::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CatalogCommand::class,
                StatusCommand::class,
                DeliveriesCommand::class,
                RetryCommand::class,
                ReplayCommand::class,
                EndpointsCommand::class,
                SweepCommand::class,
                PruneCommand::class,
            ]);
        }

        $this->schedule();
        $this->telemetryHooks();
    }

    /**
     * The sweep every minute and the prune daily, unless the host drives them
     * from its own schedule. `withoutOverlapping` because a slow sweep that is
     * still queueing must not be joined by the next one.
     */
    private function schedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make(Config::class);

            if ($config->bool('schedule.sweep', true)) {
                $schedule->command('webhooks:sweep')->everyMinute()->withoutOverlapping()->name('webhooks:sweep');
            }

            if ($config->bool('schedule.prune', true)) {
                $schedule->command('webhooks:prune')->daily()->withoutOverlapping()->name('webhooks:prune');
            }
        });
    }

    /**
     * The CloudEvents `source`: what the host configured, else the application
     * URL, else a URN that at least says who produced it. Never empty, because
     * the specification requires a non-empty URI-reference.
     */
    private function cloudEventsSource(Application $app): string
    {
        $configured = $app->make(Config::class)->string('cloudevents.source');

        if ($configured !== null) {
            return $configured;
        }

        $url = $app->make(Repository::class)->get('app.url');

        return is_string($url) && $url !== '' ? $url : 'urn:cbox:webhooks';
    }

    private function telemetryHooks(): void
    {
        if (! $this->telemetryAvailable($this->app)) {
            return;
        }

        $hooks = $this->app->make(TelemetryHooks::class);
        $hooks->subscribe($this->app->make(Dispatcher::class));
        $hooks->registerGauges();
    }

    /** The adapter is used only when the telemetry package is installed and the host has not opted out. */
    private function telemetryAvailable(Application $app): bool
    {
        return class_exists(TelemetryManager::class)
            && $app->make(Config::class)->bool('telemetry.enabled', true);
    }
}
