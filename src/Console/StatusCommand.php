<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Contracts\Monitor;
use Cbox\Webhooks\ValueObjects\EndpointStats;
use Cbox\Webhooks\ValueObjects\Window;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'webhooks:status {--endpoints : Also list the failing and slowest endpoints} {--json : Print the overview as JSON}';

    protected $description = 'The engine at a glance: backlog, outcomes over 24h, endpoint health.';

    public function handle(Monitor $monitor): int
    {
        $overview = $monitor->overview();

        if ($this->option('json') === true) {
            $this->line((string) json_encode($overview->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Pending', (string) $overview->pending);
        $this->components->twoColumnDetail('In flight', (string) $overview->inFlight);
        $this->components->twoColumnDetail('Due, overdue by more than a minute', (string) $overview->dueOverdue);
        $this->components->twoColumnDetail('Oldest pending age (s)', (string) ($overview->oldestPendingAgeSeconds ?? '-'));
        $this->components->twoColumnDetail('Delivered (24h)', (string) $overview->delivered24h);
        $this->components->twoColumnDetail('Failed, retrying (24h)', (string) $overview->failed24h);
        $this->components->twoColumnDetail('Exhausted (24h)', (string) $overview->exhausted24h);
        $this->components->twoColumnDetail('Success rate (24h)', $overview->successRate24h === null ? '-' : round($overview->successRate24h * 100, 1).' %');
        $this->components->twoColumnDetail('Endpoints active / paused / disabled / tripped', sprintf('%d / %d / %d / %d', $overview->endpointsActive, $overview->endpointsPaused, $overview->endpointsDisabled, $overview->endpointsTripped));
        $this->components->twoColumnDetail('Inbound received / failed (24h)', sprintf('%d / %d', $overview->inbound24h, $overview->inboundFailed24h));

        if ($this->option('endpoints') === true) {
            $this->newLine();
            $this->components->info('Failing endpoints');
            $this->statsTable($monitor->failingEndpoints(10));
            $this->newLine();
            $this->components->info('Slowest endpoints (24h)');
            $this->statsTable($monitor->slowestEndpoints(10, Window::lastHours(24)));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<EndpointStats>  $stats
     */
    private function statsTable(array $stats): void
    {
        if ($stats === []) {
            $this->line('  (none)');

            return;
        }

        $this->table(
            ['Endpoint', 'Delivered', 'Failed', 'Exhausted', 'p50 ms', 'p95 ms', 'Consecutive failures', 'Tripped', 'Last error'],
            array_map(static fn (EndpointStats $s): array => [
                $s->endpointId,
                $s->delivered,
                $s->failed,
                $s->exhausted,
                $s->p50DurationMs ?? '-',
                $s->p95DurationMs ?? '-',
                $s->health->consecutiveFailures,
                $s->health->isTripped() ? 'yes' : 'no',
                mb_strimwidth((string) $s->health->lastError, 0, 40, '…'),
            ], $stats),
        );
    }
}
