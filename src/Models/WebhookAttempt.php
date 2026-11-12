<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Every HTTP attempt, kept until pruned: the monitoring truth and what an
 * operator reads when a receiver says "we never got it". Each row carries its
 * delivery's scope, so an owner's attempts are listed and erased by an index.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $endpoint_id
 * @property string|null $partition
 * @property string|null $scope_key
 * @property string|null $batch_id
 * @property int $attempt_number
 * @property CarbonImmutable $started_at
 * @property int $duration_ms
 * @property AttemptOutcome $outcome
 * @property int|null $response_code
 * @property string|null $response_excerpt
 * @property string|null $error
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
class WebhookAttempt extends Model
{
    use HasUlids;
    use UsesTablePrefix;

    protected const string TABLE = 'attempts';

    public const UPDATED_AT = null;

    protected $guarded = [];

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
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'outcome' => AttemptOutcome::class,
            'response_code' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
