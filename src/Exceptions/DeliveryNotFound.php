<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

final class DeliveryNotFound extends WebhooksException
{
    public static function id(string $id): self
    {
        return new self(sprintf('Webhook delivery [%s] does not exist.', $id));
    }
}
