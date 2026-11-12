<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\ValueObjects\AttemptFilter;
use Illuminate\Validation\Rule;

final class ListAttemptsRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'endpoint_id' => ['sometimes', 'string', 'max:26'],
            'delivery_id' => ['sometimes', 'string', 'max:26'],
            'outcome' => ['sometimes', Rule::enum(AttemptOutcome::class)],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ];
    }

    public function toFilter(): AttemptFilter
    {
        $outcome = $this->optionalString('outcome');
        $since = $this->optionalString('since');
        $until = $this->optionalString('until');

        return new AttemptFilter(
            endpointId: $this->optionalString('endpoint_id'),
            deliveryId: $this->optionalString('delivery_id'),
            outcome: $outcome === null ? null : AttemptOutcome::tryFrom($outcome),
            since: $since === null ? null : CarbonImmutable::parse($since),
            until: $until === null ? null : CarbonImmutable::parse($until),
            limit: $this->optionalInt('limit') ?? 100,
        );
    }

    public function cursor(): ?string
    {
        return $this->optionalString('cursor');
    }
}
