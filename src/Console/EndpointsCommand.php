<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Models;
use Illuminate\Console\Command;

final class EndpointsCommand extends Command
{
    protected $signature = 'webhooks:endpoints {--scope= : partition/key, e.g. "env_1/org_1"; "*/org_1" for no partition} {--status=}';

    protected $description = 'List endpoints, across every scope or one.';

    public function handle(Models $models): int
    {
        $class = $models->endpoint();
        $query = $class::query()->orderBy('created_at');

        $scope = $this->option('scope');
        $status = $this->option('status');

        if (is_string($scope) && $scope !== '') {
            [$partition, $key] = array_pad(explode('/', $scope, 2), 2, '*');
            $query->where(fn ($q) => $partition === '*' ? $q->whereNull('partition') : $q->where('partition', $partition));
            $query->where(fn ($q) => $key === '*' ? $q->whereNull('scope_key') : $q->where('scope_key', $key));
        }

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $rows = $query->get()->map(static fn (WebhookEndpoint $e): array => [
            $e->id,
            $e->scope()->toString(),
            mb_strimwidth($e->url, 0, 48, '…'),
            $e->status->value.($e->disabled_reason !== null ? ' ('.$e->disabled_reason.')' : ''),
            $e->signature_scheme,
            $e->batch_size,
            $e->last_sequence,
            $e->consecutive_failures,
            $e->last_success_at?->toDateTimeString() ?? '-',
        ])->all();

        if ($rows === []) {
            $this->components->info('No endpoints match.');

            return self::SUCCESS;
        }

        $this->table(['Endpoint', 'Scope', 'URL', 'Status', 'Scheme', 'Batch', 'Seq', 'Failures', 'Last success'], $rows);

        return self::SUCCESS;
    }
}
