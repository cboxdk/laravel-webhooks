<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;

/** Every handler for an inbound item finished. */
final readonly class WebhookProcessed
{
    public function __construct(
        public IncomingWebhook $webhook,
        public int $durationMs,
    ) {}
}
