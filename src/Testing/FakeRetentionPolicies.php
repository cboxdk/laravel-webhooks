<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Testing;

use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * Retention per scope, in memory. A scope nothing was set for gets
 * `new Retention`, which is the configured default.
 */
final class FakeRetentionPolicies implements RetentionPolicies
{
    /** @var list<array{0: Scope, 1: Retention}> */
    private array $policies = [];

    public function set(Scope $scope, Retention $retention): self
    {
        $this->policies = [
            ...array_values(array_filter($this->policies, static fn (array $policy): bool => ! $policy[0]->equals($scope))),
            [$scope, $retention],
        ];

        return $this;
    }

    public function for(Scope $scope): Retention
    {
        foreach ($this->policies as [$owner, $retention]) {
            if ($owner->equals($scope)) {
                return $retention;
            }
        }

        return new Retention;
    }
}
