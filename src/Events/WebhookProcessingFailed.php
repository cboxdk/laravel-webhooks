<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Throwable;

/** A handler threw; the inbound row is marked failed with the error. */
final readonly class WebhookProcessingFailed
{
    public function __construct(
        public IncomingWebhook $webhook,
        public Throwable $exception,
    ) {}
}
