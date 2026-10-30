<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * The operator's intent for an endpoint. Health (the breaker, failure counters)
 * is kept on separate columns so a transient outage never changes intent;
 * `Disabled` is the one exception, set by the engine when a receiver says 410
 * or has been unreachable past the configured window, and cleared by resume().
 */
enum EndpointStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
}
