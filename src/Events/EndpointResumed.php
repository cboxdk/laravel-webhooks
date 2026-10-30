<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Endpoint;

/** An operator resumed the endpoint; `requeued` pending deliveries were queued in order. */
final readonly class EndpointResumed
{
    public function __construct(
        public Endpoint $endpoint,
        public int $requeued,
    ) {}
}
