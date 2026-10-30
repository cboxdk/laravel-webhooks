<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\InboundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The receiver's idempotency ledger: one row per (endpoint name, delivery id),
 * with the item's envelope kept so a queued or failed processing can be run
 * from the row alone.
 *
 * @property string $id
 * @property string $endpoint_name
 * @property string $delivery_id
 * @property string|null $event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property InboundStatus $status
 * @property string|null $error
 */
class WebhookInboundDelivery extends Model
{
    use HasUlids;
    use UsesTablePrefix;

    protected const string TABLE = 'inbound_deliveries';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'status' => InboundStatus::class,
        ];
    }
}
