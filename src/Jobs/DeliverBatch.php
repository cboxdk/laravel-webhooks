<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Jobs;

use Cbox\Webhooks\Contracts\Deliverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One request for one batch. Same rules as {@see DeliverWebhook}: one try,
 * unique by batch id, the flusher's conditional grouping is the claim, the
 * timeout is fixed at dispatch from the transport's budget.
 */
final class DeliverBatch implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $batchId, int $strandedAfterSeconds, int $timeoutSeconds = 45)
    {
        $this->uniqueFor = $strandedAfterSeconds;
        $this->timeout = $timeoutSeconds;
    }

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(Deliverer $deliverer): void
    {
        $deliverer->deliverBatch($this->batchId);
    }
}
