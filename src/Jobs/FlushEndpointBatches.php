<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Jobs;

use Cbox\Webhooks\Contracts\BatchFlusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Groups one endpoint's due pending deliveries into batches. Queued when a
 * delivery is created for a batching endpoint: delayed by the batch window so
 * the window can fill, or immediately when the pending count has reached the
 * batch size. The sweep runs the same flush every minute as a backstop.
 *
 * Unique per endpoint and kind, so a burst of publishes queues one delayed
 * flush and one immediate flush rather than one of each per publish, and the
 * immediate kind is never suppressed by a delayed one still waiting.
 */
final class FlushEndpointBatches implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor;

    public function __construct(public readonly string $endpointId, public readonly bool $immediate, int $windowSeconds)
    {
        $this->uniqueFor = $immediate ? 60 : $windowSeconds + 60;
    }

    public function uniqueId(): string
    {
        return $this->endpointId.':'.($this->immediate ? 'now' : 'window');
    }

    public function handle(BatchFlusher $flusher): void
    {
        $flusher->flush($this->endpointId);
    }
}
