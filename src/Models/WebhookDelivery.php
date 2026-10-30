<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One (event, endpoint) pair: the unit a receiver dedupes on and a retry
 * re-sends. `attempt` is bumped by the conditional claim, never by a save, so
 * two workers holding the same expected attempt cannot both send.
 *
 * @property string $id
 * @property string $endpoint_id
 * @property string $event_id
 * @property string|null $partition
 * @property string|null $scope_key
 * @property string $type
 * @property int $sequence
 * @property DeliveryStatus $status
 * @property int $attempt
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $batch_id
 * @property int|null $last_response_code
 * @property string|null $last_error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $exhausted_at
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WebhookDelivery extends Model
{
    use HasUlids;
    use UsesTablePrefix;

    protected const string TABLE = 'deliveries';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'attempt' => 0,
    ];

    public function scope(): Scope
    {
        return Scope::fromColumns($this->partition, $this->scope_key);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => DeliveryStatus::class,
            'attempt' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_response_code' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'exhausted_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
