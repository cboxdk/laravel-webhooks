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
 * One HTTP attempt for one delivery, off the publishing thread.
 *
 * Pushed only after the surrounding transaction commits, so a rolled-back
 * publish sends nothing and a worker never looks for a row that is not visible
 * yet. One try: a failed attempt is recorded on the row and retried by the
 * sweep on its backoff, and a job that dies leaves its row for the stranded
 * rescue, so the queue itself never retries.
 *
 * Unique by delivery id for the stranded window, deliberately the same window
 * the sweep uses: while the lock holds, a job is presumed alive and the sweep's
 * re-queue is suppressed; once the row counts as stranded, the lock has expired
 * and the rescue can actually happen. The deliverer's conditional claim is the
 * backstop for the case where both exist anyway.
 *
 * The timeout is fixed at dispatch from the transport's budget (connect plus
 * read, plus a margin for the claim and the settle), because the worker reads
 * it before `handle()` runs; a value set inside the job would never apply.
 */
final class DeliverWebhook implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $deliveryId,
        public readonly int $expectedAttempt,
        int $strandedAfterSeconds,
        int $timeoutSeconds = 30,
    ) {
        $this->uniqueFor = $strandedAfterSeconds;
        $this->timeout = $timeoutSeconds;
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    public function handle(Deliverer $deliverer): void
    {
        $deliverer->deliver($this->deliveryId, $this->expectedAttempt);
    }
}
