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
    protected $signature = 'webhooks:replay {endpoint : The endpoint id}
        {--since= : Replay events from this time (required unless --event names them)}
        {--until=}
        {--type=* : Only these types}
        {--event=* : Only these event ids, however old}
        {--undelivered : Skip events already delivered or still under way}
        {--per-minute= : Pace the run at this many deliveries a minute}
        {--dry-run : Count what would be replayed and send nothing}
        {--limit=1000}';

    protected $description = 'Re-create deliveries for stored events to one endpoint.';

    public function handle(Endpoints $endpoints, Models $models): int
    {
        $id = $this->argument('endpoint');
        $id = is_string($id) ? $id : '';
        $since = $this->option('since');
        $until = $this->option('until');
        $types = $this->option('type');
        $events = $this->option('event');
        $limit = $this->option('limit');
        $perMinute = $this->option('per-minute');

        $eventIds = is_array($events) ? array_values(array_filter($events, 'is_string')) : [];
        $since = is_string($since) && $since !== '' ? CarbonImmutable::parse($since) : null;

        if ($since === null && $eventIds === []) {
            $this->components->error('--since or --event is required: a replay is always bounded.');

            return self::INVALID;
        }

        $class = $models->endpoint();
        $row = $class::query()->find($id);

        if ($row === null) {
            $this->components->error(sprintf('Endpoint [%s] does not exist.', $id));

            return self::FAILURE;
        }

        $request = new ReplayRequest(
            since: $since,
            until: is_string($until) && $until !== '' ? CarbonImmutable::parse($until) : null,
            types: is_array($types) ? array_values(array_filter($types, 'is_string')) : [],
            limit: is_numeric($limit) ? max(1, (int) $limit) : 1000,
            eventIds: $eventIds,
            onlyUndelivered: $this->option('undelivered') === true,
            perMinute: is_numeric($perMinute) ? max(1, (int) $perMinute) : null,
        );

        if ($this->option('dry-run') === true) {
            $this->components->info(sprintf('%d deliveries would be re-created for endpoint [%s].', $endpoints->countReplay($row->scope(), $id, $request), $id));

            return self::SUCCESS;
        }

        $count = $endpoints->replay($row->scope(), $id, $request);

        $this->components->info(sprintf('%d deliveries re-created for endpoint [%s].', $count, $id));

        return self::SUCCESS;
    }
}
