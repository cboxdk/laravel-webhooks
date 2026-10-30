<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Housekeeping\SweepReport;

/**
 * The per-minute housekeeping pass, across every scope by design: terminalise
 * orphans, re-queue due and stranded deliveries, flush due batches, apply the
 * auto-disable rule, expire previous secrets.
 */
interface Sweeper
{
    public function sweep(): SweepReport;
}
