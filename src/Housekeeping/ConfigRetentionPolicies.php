<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

use Cbox\Webhooks\Contracts\RetentionPolicies;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\ValueObjects\Retention;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * The default: `webhooks.retention.*` for every scope. While this is the
 * bound implementation the pruner works on whole tables rather than scope by
 * scope, since every scope has the same answer.
 */
final readonly class ConfigRetentionPolicies implements RetentionPolicies
{
    public function __construct(private Config $config) {}

    public function for(Scope $scope): Retention
    {
        return $this->defaults();
    }

    public function defaults(): Retention
    {
        return new Retention(
            $this->config->positiveInt('retention.delivered_days', 30),
            $this->config->positiveInt('retention.failed_days', 90),
            $this->config->positiveInt('retention.events_days', 30),
        );
    }
}
