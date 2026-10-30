<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\ValueObjects\DeliveryFilter;
use Illuminate\Validation\Rule;

final class ListDeliveriesRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'type' => ['sometimes', 'string', 'max:128'],
            'since' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function toFilter(string $endpointId): DeliveryFilter
    {
        $status = $this->optionalString('status');
        $since = $this->optionalString('since');

        return new DeliveryFilter(
            endpointId: $endpointId,
            status: $status === null ? null : DeliveryStatus::tryFrom($status),
            type: $this->optionalString('type'),
            since: $since === null ? null : CarbonImmutable::parse($since),
            limit: $this->optionalInt('limit') ?? 100,
        );
    }
}
