<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

/**
 * A partition-wide or global endpoint was requested while
 * `webhooks.allow_wide_endpoints` is off. The config flag is the operator's
 * statement that this app has a plane entitled to see every tenant's events.
 */
final class WideEndpointsNotAllowed extends WebhooksException
{
    public static function make(): self
    {
        return new self('Wide endpoints are disabled. Set webhooks.allow_wide_endpoints to true in an app whose operator plane registers them on purpose.');
    }
}
