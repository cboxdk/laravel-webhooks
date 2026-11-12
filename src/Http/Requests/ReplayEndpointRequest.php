<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\ReplayRequest;

/**
 * A replay through the management API: a time range, named events, or both.
 * `since` is required unless `event_ids` names the events. `only_undelivered`
 * skips what was delivered or is under way; `per_minute` paces the run;
 * `dry_run` answers with the count and sends nothing.
 */
final class ReplayEndpointRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'since' => ['required_without:event_ids', 'nullable', 'date'],
            'until' => ['nullable', 'date', ...($this->filled('since') ? ['after_or_equal:since'] : [])],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'max:128'],
            'event_ids' => ['sometimes', 'array', 'max:1000'],
            'event_ids.*' => ['string', 'max:64'],
            'only_undelivered' => ['sometimes', 'boolean'],
            'per_minute' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'dry_run' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function toReplayRequest(): ReplayRequest
    {
        $since = $this->optionalString('since');
        $until = $this->optionalString('until');
        $eventIds = $this->stringList('event_ids');

        return new ReplayRequest(
            since: $since === null ? null : CarbonImmutable::parse($since),
            until: $until === null ? null : CarbonImmutable::parse($until),
            types: $this->stringList('types'),
            limit: $this->optionalInt('limit') ?? ($eventIds === [] ? 1000 : count($eventIds)),
            eventIds: $eventIds,
            onlyUndelivered: $this->boolean('only_undelivered'),
            perMinute: $this->optionalInt('per_minute'),
        );
    }

    public function isDryRun(): bool
    {
        return $this->boolean('dry_run');
    }
}
