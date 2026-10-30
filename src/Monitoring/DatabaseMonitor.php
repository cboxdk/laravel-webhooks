<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\Endpoints\EndpointMapper;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Enums\InboundStatus;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\Support\ScopedQuery;
use Cbox\Webhooks\ValueObjects\EndpointStats;
use Cbox\Webhooks\ValueObjects\Overview;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\Webhooks\ValueObjects\Thresholds;
use Cbox\Webhooks\ValueObjects\Window;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers, straight from the tables. Percentiles are computed in PHP over
 * a bounded sample of the most recent attempts, because a portable percentile
 * across SQLite, MySQL and PostgreSQL is not one query; 5000 durations is
 * plenty for a p95 and cheap to pull.
 */
final readonly class DatabaseMonitor implements Monitor
{
    private const int PERCENTILE_SAMPLE = 5000;

    public function __construct(
        private Models $models,
        private EndpointMapper $mapper,
    ) {}

    public function overview(?Scope $scope = null): Overview
    {
        $now = CarbonImmutable::now();
        $dayAgo = $now->subDay();

        $deliveries = fn (): Builder => $scope === null ? $this->deliveries() : ScopedQuery::exact($this->deliveries(), $scope);
        $endpoints = fn (): Builder => $scope === null ? $this->endpoints() : ScopedQuery::exact($this->endpoints(), $scope);

        $pending = $deliveries()->where('status', DeliveryStatus::Pending->value)->count();
        $inFlight = $deliveries()->where('status', DeliveryStatus::InFlight->value)->count();
        $dueOverdue = $deliveries()
            ->where('status', DeliveryStatus::Failed->value)
            ->where('next_attempt_at', '<', $now->subMinute())
            ->count();
        $delivered = $deliveries()->where('status', DeliveryStatus::Delivered->value)->where('delivered_at', '>=', $dayAgo)->count();
        $failed = $deliveries()->where('status', DeliveryStatus::Failed->value)->where('updated_at', '>=', $dayAgo)->count();
        $exhausted = $deliveries()->where('status', DeliveryStatus::Exhausted->value)->where('exhausted_at', '>=', $dayAgo)->count();

        $oldestPending = $deliveries()->where('status', DeliveryStatus::Pending->value)->min('created_at');
        $oldestAge = is_string($oldestPending) ? max(0, $now->getTimestamp() - CarbonImmutable::parse($oldestPending)->getTimestamp()) : null;

        $settled = $delivered + $exhausted;

        $inboundClass = $this->models->inbound();
        $inbound = $inboundClass::query()->where('received_at', '>=', $dayAgo)->count();
        $inboundFailed = $inboundClass::query()->where('received_at', '>=', $dayAgo)->where('status', InboundStatus::Failed->value)->count();

        return new Overview(
            pending: $pending,
            inFlight: $inFlight,
            dueOverdue: $dueOverdue,
            delivered24h: $delivered,
            failed24h: $failed,
            exhausted24h: $exhausted,
            successRate24h: $settled === 0 ? null : round($delivered / $settled, 4),
            oldestPendingAgeSeconds: $oldestAge,
            endpointsActive: $endpoints()->where('status', EndpointStatus::Active->value)->count(),
            endpointsPaused: $endpoints()->where('status', EndpointStatus::Paused->value)->count(),
            endpointsDisabled: $endpoints()->where('status', EndpointStatus::Disabled->value)->count(),
            endpointsTripped: $endpoints()->get()->filter(fn (WebhookEndpoint $e): bool => $this->mapper->health($e)->isTripped())->count(),
            inbound24h: $scope === null ? $inbound : 0,
            inboundFailed24h: $scope === null ? $inboundFailed : 0,
        );
    }

    public function endpointStats(string $endpointId, Window $window): EndpointStats
    {
        $endpoint = $this->endpoints()->find($endpointId);

        if ($endpoint === null) {
            return new EndpointStats($endpointId);
        }

        return $this->statsFor($endpoint, $window);
    }

    public function slowestEndpoints(int $limit, Window $window): array
    {
        $attemptClass = $this->models->attempt();

        $ids = $attemptClass::query()
            ->selectRaw('endpoint_id, avg(duration_ms) as avg_ms')
            ->where('outcome', AttemptOutcome::Success->value)
            ->whereBetween('started_at', [$window->since, $window->until])
            ->groupBy('endpoint_id')
            ->orderByDesc('avg_ms')
            ->limit(max(1, $limit))
            ->pluck('endpoint_id')
            ->all();

        return $this->statsForIds(array_values(array_filter($ids, 'is_string')), $window);
    }

    public function failingEndpoints(int $limit): array
    {
        $ids = $this->endpoints()
            ->where('consecutive_failures', '>', 0)
            ->orderByDesc('consecutive_failures')
            ->orderByDesc('last_failure_at')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->all();

        return $this->statsForIds(array_values(array_filter($ids, 'is_string')), Window::lastHours(24));
    }

    public function isHealthy(Thresholds $thresholds): bool
    {
        $overview = $this->overview();

        if ($thresholds->maxDueOverdue !== null && $overview->dueOverdue > $thresholds->maxDueOverdue) {
            return false;
        }

        if ($thresholds->maxOldestPendingAgeSeconds !== null && ($overview->oldestPendingAgeSeconds ?? 0) > $thresholds->maxOldestPendingAgeSeconds) {
            return false;
        }

        if ($thresholds->maxInFlight !== null && $overview->inFlight > $thresholds->maxInFlight) {
            return false;
        }

        if ($thresholds->minSuccessRate24h !== null && $overview->successRate24h !== null && $overview->successRate24h < $thresholds->minSuccessRate24h) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $ids
     * @return list<EndpointStats>
     */
    private function statsForIds(array $ids, Window $window): array
    {
        if ($ids === []) {
            return [];
        }

        $endpoints = $this->endpoints()->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];

        foreach ($ids as $id) {
            $endpoint = $endpoints->get($id);

            if ($endpoint instanceof WebhookEndpoint) {
                $out[] = $this->statsFor($endpoint, $window);
            }
        }

        return $out;
    }

    private function statsFor(WebhookEndpoint $endpoint, Window $window): EndpointStats
    {
        $attemptClass = $this->models->attempt();

        $inWindow = fn (): Builder => $this->deliveries()
            ->where('endpoint_id', $endpoint->id)
            ->whereBetween('updated_at', [$window->since, $window->until]);

        $delivered = $inWindow()->where('status', DeliveryStatus::Delivered->value)->count();
        $failed = $inWindow()->where('status', DeliveryStatus::Failed->value)->count();
        $exhausted = $inWindow()->where('status', DeliveryStatus::Exhausted->value)->count();
        $pending = $this->deliveries()->where('endpoint_id', $endpoint->id)->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::InFlight->value])->count();

        $durations = array_values($attemptClass::query()
            ->where('endpoint_id', $endpoint->id)
            ->where('outcome', AttemptOutcome::Success->value)
            ->whereBetween('started_at', [$window->since, $window->until])
            ->orderByDesc('started_at')
            ->limit(self::PERCENTILE_SAMPLE)
            ->pluck('duration_ms')
            ->map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->sort()
            ->all());

        $settled = $delivered + $exhausted;

        return new EndpointStats(
            endpointId: $endpoint->id,
            delivered: $delivered,
            failed: $failed,
            exhausted: $exhausted,
            pending: $pending,
            successRate: $settled === 0 ? null : round($delivered / $settled, 4),
            p50DurationMs: $this->percentile($durations, 0.5),
            p95DurationMs: $this->percentile($durations, 0.95),
            lastSuccessAt: $endpoint->last_success_at,
            lastFailureAt: $endpoint->last_failure_at,
            health: $this->mapper->health($endpoint),
        );
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $p): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $index = (int) ceil($p * $count) - 1;

        return $sorted[max(0, min($count - 1, $index))];
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveries(): Builder
    {
        $class = $this->models->delivery();

        return $class::query();
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function endpoints(): Builder
    {
        $class = $this->models->endpoint();

        return $class::query();
    }
}
