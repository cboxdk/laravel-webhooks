<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Cbox\WebhookSignature\Contracts\Clock;

/**
 * The signature package verifies timestamps against its own Clock contract, which
 * defaults to the system time. The engine signs with CarbonImmutable, so a test that
 * freezes Carbon would sign "in the future" for the verifier; this clock follows Carbon.
 */
final class CarbonClock implements Clock
{
    public function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }
}
