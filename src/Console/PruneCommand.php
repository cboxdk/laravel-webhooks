<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Contracts\Pruner;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Remove deliveries, attempts, events and inbound rows past their retention.';

    public function handle(Pruner $pruner): int
    {
        $report = $pruner->prune();

        foreach ($report->toArray() as $key => $value) {
            $this->components->twoColumnDetail(ucfirst($key).' removed', (string) $value);
        }

        return self::SUCCESS;
    }
}
