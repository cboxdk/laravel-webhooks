<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Exceptions\MalformedEnvelope;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Cbox\Webhooks\ValueObjects\Scope;
use Throwable;

/**
 * The inverse of the sender's envelope: a verified body into one or more
 * {@see IncomingWebhook}s. Deny by default: an item without a delivery id and
 * a type is refused, because without them nothing can be deduped or routed.
 */
final class EnvelopeParser
{
    /**
     * @return list<IncomingWebhook>
     *
     * @throws MalformedEnvelope
     */
    public function parse(string $endpointName, string $body): array
    {
        $decoded = Json::object($body);

        if ($decoded === null) {
            throw MalformedEnvelope::because('the body is not a JSON object');
        }

        if (($decoded['batch'] ?? false) === true) {
            $items = $decoded['deliveries'] ?? null;

            if (! is_array($items)) {
                throw MalformedEnvelope::because('a batch carries no "deliveries" list');
            }

            $out = [];

            foreach ($items as $item) {
                $out[] = $this->item($endpointName, Json::map($item));
            }

            if ($out === []) {
                throw MalformedEnvelope::because('a batch with no deliveries');
            }

            return $out;
        }

        return [$this->item($endpointName, $decoded)];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(string $endpointName, array $item): IncomingWebhook
    {
        $deliveryId = $item['delivery_id'] ?? null;
        $type = $item['type'] ?? null;

        if (! is_string($deliveryId) || $deliveryId === '' || ! is_string($type) || $type === '') {
            throw MalformedEnvelope::because('an item needs a "delivery_id" and a "type"');
        }

        $scope = Json::map($item['scope'] ?? null);

        return new IncomingWebhook(
            id: is_string($item['id'] ?? null) ? $item['id'] : '',
            type: $type,
            occurredAt: $this->date($item['created_at'] ?? null),
            deliveryId: $deliveryId,
            sequence: is_int($item['sequence'] ?? null) ? $item['sequence'] : null,
            scope: Scope::fromColumns(
                is_string($scope['partition'] ?? null) ? $scope['partition'] : null,
                is_string($scope['key'] ?? null) ? $scope['key'] : null,
            ),
            apiVersion: is_string($item['api_version'] ?? null) ? $item['api_version'] : null,
            data: Json::map($item['data'] ?? null),
            endpointName: $endpointName,
            raw: $item,
            attempt: is_int($item['attempt'] ?? null) ? $item['attempt'] : null,
        );
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
