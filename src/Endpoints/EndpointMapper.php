<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

use Cbox\Webhooks\Delivery\CircuitBreaker;
use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\Endpoint;
use Cbox\Webhooks\ValueObjects\EndpointHealth;

/** The endpoint model as the API shows it: never the secret, never the model. */
final readonly class EndpointMapper
{
    public function __construct(private CircuitBreaker $breaker) {}

    public function endpoint(WebhookEndpoint $model): Endpoint
    {
        return new Endpoint(
            id: $model->id,
            scope: $model->scope(),
            url: $model->url,
            eventTypes: Json::stringList($model->event_types),
            scheme: $model->signature_scheme,
            status: $model->status,
            description: $model->description,
            disabledReason: $model->disabled_reason === null ? null : DisabledReason::tryFrom($model->disabled_reason),
            disabledAt: $model->disabled_at,
            batchSize: $model->batch_size,
            batchWindowSeconds: $model->batch_window_seconds,
            rateLimitPerMinute: $model->rate_limit_per_minute,
            headers: Json::stringMap($model->headers),
            apiVersion: $model->api_version,
            lastSequence: $model->last_sequence,
            previousSecretExpiresAt: $model->previous_secret_expires_at,
            health: $this->health($model),
            metadata: Json::map($model->metadata),
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
            envelope: $model->envelope,
        );
    }

    public function health(WebhookEndpoint $model): EndpointHealth
    {
        return new EndpointHealth(
            consecutiveFailures: $model->consecutive_failures,
            tripCount: $model->trip_count,
            circuitOpenedAt: $model->circuit_opened_at,
            circuitClosesAt: $this->breaker->closesAt($model),
            firstFailureAt: $model->first_failure_at,
            lastFailureAt: $model->last_failure_at,
            lastSuccessAt: $model->last_success_at,
            lastError: $model->last_error,
            lastResponseCode: $model->last_response_code,
        );
    }
}
