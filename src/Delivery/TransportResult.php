<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Enums\AttemptOutcome;

/** What one HTTP exchange came to, before any policy is applied to it. */
final readonly class TransportResult
{
    public function __construct(
        public AttemptOutcome $outcome,
        public int $durationMs,
        public ?int $responseCode = null,
        public ?string $responseExcerpt = null,
        public ?string $error = null,
        public ?string $requestId = null,
        public ?string $retryAfter = null,
    ) {}

    public static function policy(AttemptOutcome $outcome, ?string $error = null): self
    {
        return new self($outcome, 0, null, null, $error);
    }
}
