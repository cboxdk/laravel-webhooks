<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\InboundStatus;
use Cbox\Webhooks\Models\WebhookInboundDelivery;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Illuminate\Database\QueryException;

/**
 * The receiver's idempotency store. Recording is an insert against the unique
 * (endpoint name, delivery id) index: a duplicate is whatever lands on the
 * index, which is correct under two concurrent redeliveries where a
 * check-then-insert is not.
 */
final readonly class InboundLedger
{
    public function __construct(private Models $models) {}

    /** The new row, or null when this delivery id had been seen on this endpoint. */
    public function record(IncomingWebhook $webhook): ?WebhookInboundDelivery
    {
        $class = $this->models->inbound();

        $row = new $class;
        $row->fill([
            'endpoint_name' => $webhook->endpointName,
            'delivery_id' => $webhook->deliveryId,
            'event_id' => $webhook->id === '' ? null : $webhook->id,
            'type' => $webhook->type,
            'payload' => $webhook->raw,
            'received_at' => CarbonImmutable::now(),
            'status' => InboundStatus::Received,
        ]);

        try {
            $row->getConnection()->transaction(static function () use ($row): void {
                $row->save();
            });
        } catch (QueryException $e) {
            $message = strtolower($e->getMessage());

            if (str_contains($message, 'unique') || str_contains($message, 'duplicate')) {
                return null;
            }

            throw $e;
        }

        return $row;
    }

    public function find(string $id): ?WebhookInboundDelivery
    {
        $class = $this->models->inbound();

        return $class::query()->find($id);
    }

    public function processed(string $id): void
    {
        $class = $this->models->inbound();

        $class::query()->whereKey($id)->update([
            'status' => InboundStatus::Processed->value,
            'processed_at' => CarbonImmutable::now(),
            'error' => null,
        ]);
    }

    public function failed(string $id, string $error): void
    {
        $class = $this->models->inbound();

        $class::query()->whereKey($id)->update([
            'status' => InboundStatus::Failed->value,
            'processed_at' => CarbonImmutable::now(),
            'error' => mb_strcut($error, 0, 2000),
        ]);
    }
}
