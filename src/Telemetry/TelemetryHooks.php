<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Telemetry;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Events\DeliveryExhausted;
use Cbox\Webhooks\Events\EndpointDisabled;
use Cbox\Webhooks\Events\EndpointRecovered;
use Cbox\Webhooks\Events\EndpointTripped;
use Cbox\Webhooks\ValueObjects\Overview;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The event side of the telemetry adapter: the engine's Laravel events become
 * telemetry events with ids only (no payload, no URL), and the Monitor's
 * backlog numbers become observable gauges read at scrape time.
 *
 * An adapter over the events, not a second code path: a host without
 * laravel-telemetry gets exactly the same Laravel events to listen to.
 */
final readonly class TelemetryHooks
{
    public function __construct(
        private TelemetryManager $telemetry,
        private Monitor $monitor,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(EndpointTripped::class, function (EndpointTripped $event): void {
            $this->telemetry->event('webhooks.endpoint.tripped', [
                'webhook.endpoint_id' => $event->endpoint->id,
                'webhook.closes_at' => $event->closesAt->toIso8601String(),
                'webhook.trip_count' => $event->endpoint->health->tripCount,
            ]);
        });

        $events->listen(EndpointRecovered::class, function (EndpointRecovered $event): void {
            $this->telemetry->event('webhooks.endpoint.recovered', ['webhook.endpoint_id' => $event->endpoint->id]);
        });

        $events->listen(EndpointDisabled::class, function (EndpointDisabled $event): void {
            $this->telemetry->event('webhooks.endpoint.disabled', [
                'webhook.endpoint_id' => $event->endpoint->id,
                'webhook.reason' => $event->reason->value,
            ]);
        });

        $events->listen(DeliveryExhausted::class, function (DeliveryExhausted $event): void {
            $this->telemetry->event('webhooks.delivery.exhausted', [
                'webhook.delivery_id' => $event->delivery->id,
                'webhook.endpoint_id' => $event->delivery->endpointId,
                'webhook.type' => $event->delivery->type,
                'webhook.attempts' => $event->delivery->attempt,
                'webhook.reason' => $event->reason,
            ]);
        });
    }

    /** Observable gauges from the Monitor, evaluated when metrics are collected. */
    public function registerGauges(): void
    {
        $overview = function (): Overview {
            return $this->monitor->overview();
        };

        $this->telemetry->gauge('webhooks_pending', static fn (): int => $overview()->pending, 'Deliveries waiting for a first attempt');
        $this->telemetry->gauge('webhooks_in_flight', static fn (): int => $overview()->inFlight, 'Deliveries claimed by a worker');
        $this->telemetry->gauge('webhooks_due_overdue', static fn (): int => $overview()->dueOverdue, 'Retries overdue by more than a minute');
        $this->telemetry->gauge('webhooks_oldest_pending_age_seconds', static fn (): int => $overview()->oldestPendingAgeSeconds ?? 0, 'Age of the oldest pending delivery', 's');
        $this->telemetry->gauge('webhooks_endpoints', static function () use ($overview): array {
            $o = $overview();

            return [
                [$o->endpointsActive, ['status' => 'active']],
                [$o->endpointsPaused, ['status' => 'paused']],
                [$o->endpointsDisabled, ['status' => 'disabled']],
                [$o->endpointsTripped, ['status' => 'tripped']],
            ];
        }, 'Endpoints by status');
    }
}
