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
 * unique by batch id, the flusher's conditional grouping is the claim.
 */
final class DeliverBatch implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $batchId, int $strandedAfterSeconds)
    {
        $this->uniqueFor = $strandedAfterSeconds;
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
