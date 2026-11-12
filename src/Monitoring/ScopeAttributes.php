<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * The scope as span attributes, when the host asked for it
 * (`webhooks.telemetry.scope_attributes`). Spans only: a trace is sampled and
 * searched, so a tenant id costs nothing there. It is never a metric tag,
 * where every distinct value would be a new time series.
 */
final readonly class ScopeAttributes
{
    public function __construct(private Config $config) {}

    /**
     * @return array<string, string>
     */
    public function for(Scope $scope): array
    {
        if (! $this->config->bool('telemetry.scope_attributes', false)) {
            return [];
        }

        $attributes = [];

        if ($scope->partition !== null) {
            $attributes['webhook.scope.partition'] = $scope->partition;
        }

        if ($scope->key !== null) {
            $attributes['webhook.scope.key'] = $scope->key;
        }

        return $attributes;
    }
}
