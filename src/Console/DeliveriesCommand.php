<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Console;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Support\Models;
use Illuminate\Console\Command;

/**
 * An operator's listing, across every scope: the console is the operator
 * plane, which is why this does not go through the scoped Deliveries contract.
 */
final class DeliveriesCommand extends Command
{
    protected $signature = 'webhooks:deliveries {--endpoint= : Only this endpoint id} {--status= : pending|in_flight|delivered|failed|exhausted|cancelled} {--since= : A date or relative time, e.g. "-1 hour"} {--limit=50}';

    protected $description = 'List deliveries, newest first.';

    public function handle(Models $models): int
    {
        $class = $models->delivery();
        $query = $class::query()->orderByDesc('created_at');

        $endpoint = $this->option('endpoint');
        $status = $this->option('status');
        $since = $this->option('since');
        $limit = $this->option('limit');

        if (is_string($endpoint) && $endpoint !== '') {
            $query->where('endpoint_id', $endpoint);
        }

        if (is_string($status) && $status !== '') {
            if (DeliveryStatus::tryFrom($status) === null) {
                $this->components->error(sprintf('Unknown status [%s].', $status));

                return self::INVALID;
            }

            $query->where('status', $status);
        }

        if (is_string($since) && $since !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($since));
        }

        $rows = $query
            ->limit(is_numeric($limit) ? max(1, (int) $limit) : 50)
            ->get()
            ->map(static fn (WebhookDelivery $d): array => [
                $d->id,
                $d->endpoint_id,
                $d->type,
                $d->sequence,
                $d->status->value,
                $d->attempt,
                $d->last_response_code ?? '-',
                $d->next_attempt_at?->toDateTimeString() ?? '-',
                $d->created_at?->toDateTimeString() ?? '-',
            ])
            ->all();

        if ($rows === []) {
            $this->components->info('No deliveries match.');

            return self::SUCCESS;
        }

        $this->table(['Delivery', 'Endpoint', 'Type', 'Seq', 'Status', 'Attempt', 'Code', 'Next attempt', 'Created'], $rows);

        return self::SUCCESS;
    }
}
