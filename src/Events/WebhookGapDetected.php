<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Cbox\Webhooks\ValueObjects\Scope;

/** An inbound sequence skipped ahead of the last one seen for this endpoint and scope. Informational: the sender's at-least-once delivery may still fill it. */
final readonly class WebhookGapDetected
{
    public function __construct(
        public string $endpointName,
        public Scope $scope,
        public int $expected,
        public int $received,
    ) {}
}
