<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Endpoint;

/** A probe succeeded after a trip; the breaker closed. */
final readonly class EndpointRecovered
{
    public function __construct(
        public Endpoint $endpoint,
    ) {}
}
