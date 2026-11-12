<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * How long one scope's history is kept, in days. A null field means "the
 * configured default" (`webhooks.retention.*`), so `new Retention` is the
 * default for everything and a host states only what differs.
 *
 * `eventsDays` is the replay horizon: an event older than that cannot be
 * replayed because it is gone. The inbound ledger is not scoped and keeps
 * `webhooks.retention.inbound_days`.
 */
final readonly class Retention
{
    public function __construct(
        public ?int $deliveredDays = null,
        public ?int $failedDays = null,
        public ?int $eventsDays = null,
    ) {}

    /** The same number of days for delivered, failed and events. */
    public static function days(int $days): self
    {
        return new self($days, $days, $days);
    }

    /** This policy with every unset field taken from `$defaults`, and nothing below one day. */
    public function orElse(self $defaults): self
    {
        return new self(
            max(1, $this->deliveredDays ?? $defaults->deliveredDays ?? 30),
            max(1, $this->failedDays ?? $defaults->failedDays ?? 90),
            max(1, $this->eventsDays ?? $defaults->eventsDays ?? 30),
        );
    }

    /**
     * @return array{delivered_days: int|null, failed_days: int|null, events_days: int|null}
     */
    public function toArray(): array
    {
        return ['delivered_days' => $this->deliveredDays, 'failed_days' => $this->failedDays, 'events_days' => $this->eventsDays];
    }
}
