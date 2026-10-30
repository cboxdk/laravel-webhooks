<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

use Cbox\Webhooks\Contracts\ScopeResolver;

/**
 * The management API was mounted without a {@see ScopeResolver}
 * binding. There is deliberately no default: a default scope would be a tenant
 * boundary chosen by the package on the host's behalf.
 */
final class ScopeResolverMissing extends WebhooksException
{
    public static function make(): self
    {
        return new self('No Cbox\Webhooks\Contracts\ScopeResolver is bound. Bind one that reads the caller\'s tenant from the request before mounting the webhooks routes.');
    }
}
