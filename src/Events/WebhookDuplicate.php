<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

/** An inbound delivery id had been seen before on this endpoint; nothing was processed. */
final readonly class WebhookDuplicate
{
    public function __construct(
        public string $endpointName,
        public string $deliveryId,
    ) {}
}
