<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests;

use Cbox\Ssrf\Rules\PublicUrl;
use Cbox\Webhooks\Endpoints\EndpointPolicy;
use Cbox\Webhooks\Http\Requests\Rules\CatalogEventTypes;
use Cbox\Webhooks\Http\Requests\Rules\NoReservedHeaders;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Illuminate\Validation\Rule;

final class StoreEndpointRequest extends WebhooksFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $policy = $this->container->make(EndpointPolicy::class);

        return [
            'url' => ['required', 'string', 'max:2048', new PublicUrl($policy->allowedSchemes())],
            'event_types' => ['required', 'array', 'min:1', new CatalogEventTypes($this->catalog())],
            'event_types.*' => ['string', 'max:128'],
            'scheme' => ['sometimes', 'string', 'in:cbox,standard-webhooks'],
            'envelope' => ['sometimes', 'string', Rule::in($policy->envelopeNames())],
            'description' => ['nullable', 'string', 'max:255'],
            'batch_size' => ['sometimes', 'integer', 'min:1', 'max:'.$policy->maxBatchSize()],
            'batch_window_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.$policy->maxBatchWindow()],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'headers' => ['sometimes', 'array', $this->container->make(NoReservedHeaders::class)],
            'headers.*' => ['string'],
            'api_version' => ['nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'array'],
            'retry_schedule' => ['sometimes', 'nullable', 'array', 'max:'.EndpointPolicy::MAX_RETRY_ENTRIES],
            'retry_schedule.*' => ['integer', 'min:1', 'max:'.EndpointPolicy::MAX_RETRY_DELAY_SECONDS],
            'timeout_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$policy->maxTimeout()],
            'follow_redirects' => ['sometimes', 'boolean'],
            'transform' => ['sometimes', 'nullable', 'string', 'max:'.EndpointPolicy::MAX_TRANSFORM_LENGTH],
        ];
    }

    public function toNewEndpoint(): NewEndpoint
    {
        return new NewEndpoint(
            url: $this->optionalString('url') ?? '',
            eventTypes: $this->stringList('event_types'),
            scheme: $this->optionalString('scheme') ?? 'cbox',
            description: $this->optionalString('description'),
            batchSize: $this->optionalInt('batch_size') ?? 1,
            batchWindowSeconds: $this->optionalInt('batch_window_seconds') ?? 0,
            rateLimitPerMinute: $this->optionalInt('rate_limit_per_minute'),
            headers: $this->stringMap('headers'),
            apiVersion: $this->optionalString('api_version'),
            metadata: $this->map('metadata'),
            envelope: $this->optionalString('envelope') ?? 'cbox',
            retrySchedule: $this->input('retry_schedule') === null ? null : $this->intList('retry_schedule'),
            timeoutSeconds: $this->optionalInt('timeout_seconds'),
            followRedirects: $this->boolean('follow_redirects'),
            transform: $this->optionalString('transform'),
        );
    }
}
