<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\ValueObjects\AttemptResult;

/** The outcome of the pre-send checks: a refusal already settled, or a go-ahead. */
final readonly class Admission
{
    private function __construct(
        public ?AttemptResult $refusal,
        public bool $probing,
    ) {}

    public static function refused(AttemptResult $result): self
    {
        return new self($result, false);
    }

    public static function granted(bool $probing): self
    {
        return new self(null, $probing);
    }
}
