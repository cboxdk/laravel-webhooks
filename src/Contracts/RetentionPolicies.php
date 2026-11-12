<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * How long a scope's deliveries, attempts and events are kept.
 *
 * The default binding answers `webhooks.retention.*` for every scope. A host
 * whose customers have different retention (by plan, by contract) binds its
 * own and returns that customer's {@see Retention}; any field left null falls
 * back to the configured value, so `new Retention` means "the default".
 *
 * The pruner calls this once per scope that has rows, once a day.
 */
interface RetentionPolicies
{
    public function for(Scope $scope): Retention;
}
