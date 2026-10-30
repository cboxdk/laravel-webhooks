<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Endpoints\EndpointMapper;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Events\EndpointDisabled;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\Support\Models;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The engine's own decision to stop sending to an endpoint: the receiver said
 * 410, or nothing has succeeded since the first failure for longer than the
 * host allows. Pending and failed deliveries are cancelled, because resuming
 * later and replaying is the owner's choice, not a backlog to surprise them with.
 */
final readonly class EndpointDisabler
{
    public function __construct(
        private Models $models,
        private Config $config,
        private EndpointMapper $mapper,
        private Dispatcher $events,
        private Metrics $metrics,
    ) {}

    /** True when the auto-disable rule applies to the endpoint's current health. */
    public function shouldAutoDisable(WebhookEndpoint $endpoint, CarbonImmutable $now): bool
    {
        $firstFailure = $endpoint->first_failure_at;

        if ($firstFailure === null || $endpoint->status !== EndpointStatus::Active) {
            return false;
        }

        $cutoff = $now->subSeconds($this->config->positiveInt('disable_after_seconds', 259_200));

        if ($firstFailure->greaterThan($cutoff)) {
            return false;
        }

        return $endpoint->last_success_at === null || $endpoint->last_success_at->lessThan($firstFailure);
    }

    /** Disable, cancel the backlog, announce. Idempotent: a second call finds nothing to do. */
    public function disable(WebhookEndpoint $endpoint, DisabledReason $reason, CarbonImmutable $now): bool
    {
        $endpointClass = $this->models->endpoint();

        $changed = $endpointClass::query()
            ->whereKey($endpoint->id)
            ->where('status', '!=', EndpointStatus::Disabled->value)
            ->update([
                'status' => EndpointStatus::Disabled->value,
                'disabled_reason' => $reason->value,
                'disabled_at' => $now,
                'updated_at' => $now,
            ]);

        if ($changed !== 1) {
            return false;
        }

        $endpoint->status = EndpointStatus::Disabled;
        $endpoint->disabled_reason = $reason->value;
        $endpoint->disabled_at = $now;

        $this->cancelBacklog($endpoint->id, 'endpoint_disabled', $now);

        $this->metrics->counter('webhooks.endpoint.disabled', 1, ['reason' => $reason->value]);
        $this->events->dispatch(new EndpointDisabled($this->mapper->endpoint($endpoint), $reason));

        return true;
    }

    /** Every delivery that is not terminal becomes cancelled, in one update. */
    public function cancelBacklog(string $endpointId, string $reason, CarbonImmutable $now): int
    {
        $deliveryClass = $this->models->delivery();

        return $deliveryClass::query()
            ->where('endpoint_id', $endpointId)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value, DeliveryStatus::InFlight->value])
            ->update([
                'status' => DeliveryStatus::Cancelled->value,
                'next_attempt_at' => null,
                'last_error' => $reason,
                'updated_at' => $now,
            ]);
    }
}
