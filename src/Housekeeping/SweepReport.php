<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Housekeeping;

/** What one sweep did, for the command's output and a host's logging. */
final readonly class SweepReport
{
    public function __construct(
        public int $orphansTerminalised = 0,
        public int $strandedRescued = 0,
        public int $retriesQueued = 0,
        public int $batchesQueued = 0,
        public int $endpointsDisabled = 0,
        public int $secretsExpired = 0,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'orphans_terminalised' => $this->orphansTerminalised,
            'stranded_rescued' => $this->strandedRescued,
            'retries_queued' => $this->retriesQueued,
            'batches_queued' => $this->batchesQueued,
            'endpoints_disabled' => $this->endpointsDisabled,
            'secrets_expired' => $this->secretsExpired,
        ];
    }
}
