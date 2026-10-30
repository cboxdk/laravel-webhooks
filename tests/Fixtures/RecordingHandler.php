<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Tests\Fixtures;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;

/** A class handler, resolved from the container, that remembers what it saw. */
final class RecordingHandler
{
    /** @var list<IncomingWebhook> */
    public static array $seen = [];

    public function __invoke(IncomingWebhook $webhook): void
    {
        self::$seen[] = $webhook;
    }
}
