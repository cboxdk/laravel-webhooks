<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/** A time window for statistics, ending now unless told otherwise. */
final readonly class Window
{
    public function __construct(
        public CarbonImmutable $since,
        public CarbonImmutable $until,
    ) {}

    public static function lastSeconds(int $seconds): self
    {
        $now = CarbonImmutable::now();

        return new self($now->subSeconds(max(1, $seconds)), $now);
    }

    public static function lastHours(int $hours): self
    {
        return self::lastSeconds($hours * 3600);
    }
}
