<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Catalog\CatalogMarkdown;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Console\Command;

final class CatalogCommand extends Command
{
    protected $signature = 'webhooks:catalog {--markdown : Render the docs tables instead of the console table} {--scope= : The catalog of one scope, as partition/key, e.g. "env_1/org_1"; "*/org_1" for no partition}';

    protected $description = 'List the event types this application publishes.';

    public function handle(EventCatalog $catalog, EventCatalogs $catalogs, CatalogMarkdown $markdown): int
    {
        $scope = $this->option('scope');

        if (is_string($scope) && $scope !== '') {
            [$partition, $key] = array_pad(explode('/', $scope, 2), 2, '*');

            $catalog = $catalogs->for(Scope::fromColumns($partition === '*' ? null : $partition, $key === '*' ? null : $key));
        }

        if ($this->option('markdown') === true) {
            $this->output->write($markdown->render($catalog));

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
