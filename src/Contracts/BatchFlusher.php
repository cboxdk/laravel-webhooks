<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

/**
 * Groups due pending deliveries of batching endpoints into batches and queues
 * one job per batch. Idempotent: the grouping is a conditional update, so a
 * second flusher running at the same moment finds nothing left to group.
 */
interface BatchFlusher
{
    /** @return int the number of batches queued */
    public function flush(?string $endpointId = null, bool $force = false): int;
}
