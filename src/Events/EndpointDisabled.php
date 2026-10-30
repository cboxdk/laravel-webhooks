<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\ValueObjects\Endpoint;

/** The engine disabled an endpoint on its own. The host tells the owner. */
final readonly class EndpointDisabled
{
    public function __construct(
        public Endpoint $endpoint,
        public DisabledReason $reason,
    ) {}
}
