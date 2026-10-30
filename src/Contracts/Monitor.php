<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\EndpointStats;
use Cbox\Webhooks\ValueObjects\Overview;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\Webhooks\ValueObjects\Thresholds;
use Cbox\Webhooks\ValueObjects\Window;

interface Monitor
{
    /** Engine-wide, or one scope's share of it. */
    public function overview(?Scope $scope = null): Overview;

    public function endpointStats(string $endpointId, Window $window): EndpointStats;

    /** @return list<EndpointStats> */
    public function slowestEndpoints(int $limit, Window $window): array;

    /** @return list<EndpointStats> */
    public function failingEndpoints(int $limit): array;

    /** The health-check helper a host wires into its health package. */
    public function isHealthy(Thresholds $thresholds): bool;
}
