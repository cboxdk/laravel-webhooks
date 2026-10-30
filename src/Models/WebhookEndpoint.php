<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A subscriber endpoint. `status` is the operator's intent; the failure
 * counters and the breaker columns are health, driven by the deliverer and
 * self-healing after a success. The two are kept apart so a transient outage
 * never changes what the owner asked for.
 *
 * The signing secret is stored under Laravel's encrypter. The previous secret
 * stays beside it for the rotation overlap so the receiver's secret set can
 * accept both; the deliverer always signs with the current one.
 *
 * @property string $id
 * @property string|null $partition
 * @property string|null $scope_key
 * @property string $url
 * @property string|null $description
 * @property array<int, mixed> $event_types
 * @property string $signature_scheme
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property EndpointStatus $status
 * @property string|null $disabled_reason
 * @property CarbonImmutable|null $disabled_at
 * @property int $batch_size
 * @property int $batch_window_seconds
 * @property int|null $rate_limit_per_minute
 * @property array<string, mixed>|null $headers
 * @property string|null $api_version
 * @property int $last_sequence
 * @property int $consecutive_failures
 * @property int $trip_count
 * @property CarbonImmutable|null $circuit_opened_at
 * @property CarbonImmutable|null $first_failure_at
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $last_success_at
 * @property string|null $last_error
 * @property int|null $last_response_code
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WebhookEndpoint extends Model
{
    use HasUlids;
    use UsesTablePrefix;

    protected const string TABLE = 'endpoints';

    protected $guarded = [];

    /**
     * Mirror the column defaults so a model that has not been re-read after
     * insert reports what it will be delivered with.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'signature_scheme' => 'cbox',
        'status' => 'active',
        'batch_size' => 1,
        'batch_window_seconds' => 0,
        'last_sequence' => 0,
        'consecutive_failures' => 0,
        'trip_count' => 0,
    ];

    public function scope(): Scope
    {
        return Scope::fromColumns($this->partition, $this->scope_key);
    }

    public function isBatching(): bool
    {
        return $this->batch_size > 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'status' => EndpointStatus::class,
            'disabled_at' => 'immutable_datetime',
            'batch_size' => 'integer',
            'batch_window_seconds' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'headers' => 'array',
            'last_sequence' => 'integer',
            'consecutive_failures' => 'integer',
            'trip_count' => 'integer',
            'circuit_opened_at' => 'immutable_datetime',
            'first_failure_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_response_code' => 'integer',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
