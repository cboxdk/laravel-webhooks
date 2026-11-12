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
            'until' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'endpoint_id' => ['sometimes', 'string', 'max:26'],
            'event_id' => ['sometimes', 'string', 'max:26'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ];
    }

    /**
     * @param  string|null  $endpointId  from the route when the listing is one endpoint's; otherwise the `endpoint_id` filter
     */
    public function toFilter(?string $endpointId = null): DeliveryFilter
    {
        $status = $this->optionalString('status');
        $since = $this->optionalString('since');
        $until = $this->optionalString('until');

        return new DeliveryFilter(
            endpointId: $endpointId ?? $this->optionalString('endpoint_id'),
            status: $status === null ? null : DeliveryStatus::tryFrom($status),
            type: $this->optionalString('type'),
            since: $since === null ? null : CarbonImmutable::parse($since),
            limit: $this->optionalInt('limit') ?? 100,
            until: $until === null ? null : CarbonImmutable::parse($until),
            eventId: $this->optionalString('event_id'),
        );
    }

    public function cursor(): ?string
    {
        return $this->optionalString('cursor');
    }
}
