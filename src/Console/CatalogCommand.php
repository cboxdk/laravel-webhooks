<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Catalog\CatalogMarkdown;
use Cbox\Webhooks\Contracts\EventCatalog;
use Illuminate\Console\Command;

final class CatalogCommand extends Command
{
    protected $signature = 'webhooks:catalog {--markdown : Render the docs tables instead of the console table}';

    protected $description = 'List the event types this application publishes.';

    public function handle(EventCatalog $catalog, CatalogMarkdown $markdown): int
    {
        if ($this->option('markdown') === true) {
            $this->output->write($markdown->render());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($catalog->all() as $definition) {
            $rows[] = [
                $definition->type,
                $definition->group,
                $definition->label,
                $definition->supersededBy !== null ? 'legacy → '.$definition->supersededBy : ($definition->offered ? 'current' : 'not offered'),
            ];
        }

        if ($rows === []) {
            $this->components->warn('The catalog is empty. Register EventDefinitions from a service provider.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'Group', 'Label', 'Status'], $rows);

        return self::SUCCESS;
    }
}
