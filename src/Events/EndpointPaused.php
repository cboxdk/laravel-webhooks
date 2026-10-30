<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Endpoint;

/** An operator paused the endpoint. */
final readonly class EndpointPaused
{
    public function __construct(
        public Endpoint $endpoint,
    ) {}
}
