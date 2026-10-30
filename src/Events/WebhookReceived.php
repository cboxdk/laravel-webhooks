<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;

/** A verified inbound item was recorded and will be (or was) processed. */
final readonly class WebhookReceived
{
    public function __construct(
        public IncomingWebhook $webhook,
    ) {}
}
