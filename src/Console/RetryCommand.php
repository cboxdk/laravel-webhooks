<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Contracts\Deliveries;
use Cbox\Webhooks\Support\Models;
use Illuminate\Console\Command;

final class RetryCommand extends Command
{
    protected $signature = 'webhooks:retry {delivery : The delivery id}';

    protected $description = 'Queue a failed or exhausted delivery for another attempt now.';

    public function handle(Deliveries $deliveries, Models $models): int
    {
        $id = $this->argument('delivery');
        $id = is_string($id) ? $id : '';

        $class = $models->delivery();
        $row = $class::query()->find($id);

        if ($row === null) {
            $this->components->error(sprintf('Delivery [%s] does not exist.', $id));

            return self::FAILURE;
        }

        if (! $deliveries->retry($row->scope(), $id)) {
            $this->components->error(sprintf('Delivery [%s] is %s; only failed or exhausted deliveries can be retried.', $id, $row->status->value));

            return self::FAILURE;
        }

        $this->components->info(sprintf('Delivery [%s] queued.', $id));

        return self::SUCCESS;
    }
}
