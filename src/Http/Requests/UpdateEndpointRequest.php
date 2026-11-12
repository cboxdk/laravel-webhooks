<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Cbox\Ssrf\Rules\PublicUrl;
use Cbox\Webhooks\Endpoints\EndpointPolicy;
use Cbox\Webhooks\Http\Requests\Rules\CatalogEventTypes;
use Cbox\Webhooks\Http\Requests\Rules\NoReservedHeaders;
use Cbox\Webhooks\ValueObjects\EndpointChanges;
use Illuminate\Validation\Rule;

final class UpdateEndpointRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $policy = $this->container->make(EndpointPolicy::class);

        return [
            'url' => ['sometimes', 'string', 'max:2048', new PublicUrl($policy->allowedSchemes())],
            'event_types' => ['sometimes', 'array', 'min:1', new CatalogEventTypes($this->catalog())],
            'event_types.*' => ['string', 'max:128'],
            'envelope' => ['sometimes', 'string', Rule::in($policy->envelopeNames())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'batch_size' => ['sometimes', 'integer', 'min:1', 'max:'.$policy->maxBatchSize()],
            'batch_window_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.$policy->maxBatchWindow()],
            'rate_limit_per_minute' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'headers' => ['sometimes', 'array', $this->container->make(NoReservedHeaders::class)],
            'headers.*' => ['string'],
            'api_version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'array'],
            'retry_schedule' => ['sometimes', 'nullable', 'array', 'max:'.EndpointPolicy::MAX_RETRY_ENTRIES],
            'retry_schedule.*' => ['integer', 'min:1', 'max:'.EndpointPolicy::MAX_RETRY_DELAY_SECONDS],
            'timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$policy->maxTimeout()],
            'follow_redirects' => ['sometimes', 'boolean'],
            'transform' => ['sometimes', 'nullable', 'string', 'max:'.EndpointPolicy::MAX_TRANSFORM_LENGTH],
        ];
    }

    public function toChanges(): EndpointChanges
    {
        return new EndpointChanges(
            url: $this->optionalString('url'),
            description: $this->optionalString('description'),
            eventTypes: $this->has('event_types') ? $this->stringList('event_types') : null,
            batchSize: $this->optionalInt('batch_size'),
            batchWindowSeconds: $this->optionalInt('batch_window_seconds'),
            rateLimitPerMinute: $this->optionalInt('rate_limit_per_minute'),
            headers: $this->has('headers') ? $this->stringMap('headers') : null,
            apiVersion: $this->optionalString('api_version'),
            metadata: $this->has('metadata') ? $this->map('metadata') : null,
            clearRateLimit: $this->has('rate_limit_per_minute') && $this->input('rate_limit_per_minute') === null,
            clearApiVersion: $this->has('api_version') && $this->input('api_version') === null,
            clearDescription: $this->has('description') && $this->input('description') === null,
            envelope: $this->optionalString('envelope'),
            retrySchedule: $this->has('retry_schedule') && $this->input('retry_schedule') !== null ? $this->intList('retry_schedule') : null,
            timeoutSeconds: $this->optionalInt('timeout_seconds'),
            followRedirects: $this->optionalBool('follow_redirects'),
            transform: $this->optionalString('transform'),
            clearRetrySchedule: $this->has('retry_schedule') && $this->input('retry_schedule') === null,
            clearTimeout: $this->has('timeout_seconds') && $this->input('timeout_seconds') === null,
            clearTransform: $this->has('transform') && $this->input('transform') === null,
        );
    }
}
