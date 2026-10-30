<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

final class MalformedEnvelope extends WebhooksException
{
    public static function because(string $reason): self
    {
        return new self('Malformed webhook envelope: '.$reason);
    }
}
