<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Contracts\Sweeper;
use Illuminate\Console\Command;

final class SweepCommand extends Command
{
    protected $signature = 'webhooks:sweep';

    protected $description = 'Re-queue due retries and stranded deliveries, flush batches, apply auto-disable, expire old secrets.';

    public function handle(Sweeper $sweeper): int
    {
        $report = $sweeper->sweep();

        foreach ($report->toArray() as $key => $value) {
            $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($key)), (string) $value);
        }

        return self::SUCCESS;
    }
}
