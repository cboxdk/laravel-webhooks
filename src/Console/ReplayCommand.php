<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\ValueObjects\ReplayRequest;
use Illuminate\Console\Command;

final class ReplayCommand extends Command
{
    protected $signature = 'webhooks:replay {endpoint : The endpoint id} {--since= : Replay events from this time (required)} {--until=} {--type=* : Only these types} {--limit=1000}';

    protected $description = 'Re-create deliveries for stored events to one endpoint.';

    public function handle(Endpoints $endpoints, Models $models): int
    {
        $id = $this->argument('endpoint');
        $id = is_string($id) ? $id : '';
        $since = $this->option('since');
        $until = $this->option('until');
        $types = $this->option('type');
        $limit = $this->option('limit');

        if (! is_string($since) || $since === '') {
            $this->components->error('--since is required: a replay is always bounded.');

            return self::INVALID;
        }

        $class = $models->endpoint();
        $row = $class::query()->find($id);

        if ($row === null) {
            $this->components->error(sprintf('Endpoint [%s] does not exist.', $id));

            return self::FAILURE;
        }

        $count = $endpoints->replay($row->scope(), $id, new ReplayRequest(
            since: CarbonImmutable::parse($since),
            until: is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null,
            types: is_array($types) ? array_values(array_filter($types, 'is_string')) : [],
            limit: is_numeric($limit) ? max(1, (int) $limit) : 1000,
        ));

        $this->components->info(sprintf('%d deliveries re-created for endpoint [%s].', $count, $id));

        return self::SUCCESS;
    }
}
