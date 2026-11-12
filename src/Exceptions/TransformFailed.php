<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

use Cbox\Webhooks\Contracts\PayloadTransformer;

/**
 * A payload transform could not produce a body for this event: a field it
 * needs is missing, a function failed, the result was not JSON. Thrown by a
 * {@see PayloadTransformer} at delivery; the delivery
 * then fails permanently, because the same event and expression will fail the
 * same way on every retry. The message is kept on the attempt, so it should
 * say what went wrong without echoing the payload.
 */
final class TransformFailed extends WebhooksException
{
    public static function nullResult(): self
    {
        return new self('The transform produced null.');
    }

    public static function notJson(string $reason): self
    {
        return new self('The transform result cannot be sent as JSON: '.$reason);
    }
}
