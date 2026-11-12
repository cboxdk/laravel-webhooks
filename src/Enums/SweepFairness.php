<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * How the sweep spends its per-tick limit when more rows are due than it may take.
 */
enum SweepFairness: string
{
    /** Oldest first across every scope. One busy scope can take the whole tick. */
    case None = 'none';

    /** Round-robin over (partition, key): every owner's oldest row before anyone's second. */
    case Scope = 'scope';

    /** Round-robin over the partition, for a host whose tenant is the partition. */
    case Partition = 'partition';
}
