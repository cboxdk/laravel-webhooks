<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Housekeeping\PruneReport;

interface Pruner
{
    public function prune(): PruneReport;
}
