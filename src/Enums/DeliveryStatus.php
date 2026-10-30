<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * One delivery's lifecycle. `Pending` and `Failed` are the two claimable states;
 * `InFlight` is held by exactly one worker; the last three are terminal.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case InFlight = 'in_flight';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Exhausted, self::Cancelled => true,
            default => false,
        };
    }

    /** The states a worker may claim an attempt from. */
    public function isClaimable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
