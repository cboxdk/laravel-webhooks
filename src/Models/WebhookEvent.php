<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Models;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What was published: the replay source and the record of what this app said.
 * One row per publish, however many endpoints it fanned out to.
 *
 * @property string $id
 * @property string|null $partition
 * @property string|null $scope_key
 * @property string $type
 * @property string|null $idempotency_key
 * @property string|null $dedup_hash
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
class WebhookEvent extends Model
{
    use HasUlids;
    use UsesTablePrefix;

    protected const string TABLE = 'events';

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
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
