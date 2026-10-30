<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Delivery;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * Read and retry deliveries, scoped exactly like {@see Endpoints}.
 */
interface Deliveries
{
    /** With its attempts. */
    public function find(Scope $scope, string $id): ?Delivery;

    /** @return list<Delivery> */
    public function list(Scope $scope, DeliveryFilter $filter): array;

    /**
     * Queue a failed or exhausted delivery for another attempt now. False when
     * the delivery is unknown in this scope or not in a retryable state.
     */
    public function retry(Scope $scope, string $id): bool;
}
