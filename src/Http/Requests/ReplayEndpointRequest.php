<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\ReplayRequest;

final class ReplayEndpointRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'since' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:since'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function toReplayRequest(): ReplayRequest
    {
        $until = $this->optionalString('until');

        return new ReplayRequest(
            since: CarbonImmutable::parse($this->optionalString('since') ?? 'now'),
            until: $until === null ? null : CarbonImmutable::parse($until),
            types: $this->stringList('types'),
            limit: $this->optionalInt('limit') ?? 1000,
        );
    }
}
