<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

final class PayloadTooLarge extends WebhooksException
{
    public static function bytes(int $actual, int $limit): self
    {
        return new self(sprintf('Webhook payload is %d bytes; the limit (webhooks.max_payload_bytes) is %d.', $actual, $limit));
    }
}
