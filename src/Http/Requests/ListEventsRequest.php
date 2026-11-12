<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\EventFilter;

final class ListEventsRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'max:128'],
            'since' => ['sometimes', 'date'],
            'until' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ];
    }

    public function toFilter(): EventFilter
    {
        $since = $this->optionalString('since');
        $until = $this->optionalString('until');

        return new EventFilter(
            type: $this->optionalString('type'),
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
