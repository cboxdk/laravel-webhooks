<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

/** A pagination cursor that this package did not issue, or that was altered. */
final class InvalidCursor extends WebhooksException
{
    public static function make(): self
    {
        return new self('The pagination cursor is not valid. Start again without one.');
    }
}
