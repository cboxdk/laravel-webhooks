<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\Delivery;

/** Models to value objects, the only direction the package ever converts. */
final class DeliveryMapper
{
    /**
     * @param  list<Attempt>|null  $attempts
     */
    public static function delivery(WebhookDelivery $model, ?array $attempts = null): Delivery
    {
        return new Delivery(
            id: $model->id,
            endpointId: $model->endpoint_id,
            eventId: $model->event_id,
            scope: $model->scope(),
            type: $model->type,
            sequence: $model->sequence,
            status: $model->status,
            attempt: $model->attempt,
            nextAttemptAt: $model->next_attempt_at,
            batchId: $model->batch_id,
            lastResponseCode: $model->last_response_code,
            lastError: $model->last_error,
            deliveredAt: $model->delivered_at,
            exhaustedAt: $model->exhausted_at,
            durationMs: $model->duration_ms,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
            attempts: $attempts,
        );
    }

    public static function attempt(WebhookAttempt $model): Attempt
    {
        return new Attempt(
            id: $model->id,
            deliveryId: $model->delivery_id,
            endpointId: $model->endpoint_id,
            number: $model->attempt_number,
            outcome: $model->outcome,
            startedAt: $model->started_at,
            durationMs: $model->duration_ms,
            responseCode: $model->response_code,
            responseExcerpt: $model->response_excerpt,
            error: $model->error,
            requestId: $model->request_id,
            batchId: $model->batch_id,
            scope: $model->scope(),
        );
    }
}
