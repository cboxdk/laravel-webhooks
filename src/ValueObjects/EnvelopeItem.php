<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Everything an envelope format may put on the wire for one delivery. Plain
 * values, so a format never sees a model.
 *
 * `eventId` is the same across every endpoint an event went to and across a
 * replay; `deliveryId` is unique per (event, endpoint) and is what a receiver
 * deduplicates on; `sequence` is per endpoint and gapless.
 *
 * When the endpoint has a transform, `transformed` holds its result and
 * `isTransformed` is true; a format puts {@see payload()} where the payload
 * goes, so it never has to know whether a transform ran. `data` stays the
 * published payload either way.
 */
final readonly class EnvelopeItem
{
    /**
     * @param  array<string, mixed>  $data  the published payload, untouched
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public CarbonImmutable $occurredAt,
        public string $deliveryId,
        public int $attempt,
        public int $sequence,
        public Scope $scope,
        public array $data = [],
        public ?string $apiVersion = null,
        public string $endpointId = '',
        public mixed $transformed = null,
        public bool $isTransformed = false,
    ) {}

    /** What goes on the wire as the payload: the transform's result, or the published data. */
    public function payload(): mixed
    {
        return $this->isTransformed ? $this->transformed : $this->data;
    }

    public function withTransformed(mixed $result): self
    {
        return new self(
            eventId: $this->eventId,
            type: $this->type,
            occurredAt: $this->occurredAt,
            deliveryId: $this->deliveryId,
            attempt: $this->attempt,
            sequence: $this->sequence,
            scope: $this->scope,
            data: $this->data,
            apiVersion: $this->apiVersion,
            endpointId: $this->endpointId,
            transformed: $result,
            isTransformed: true,
        );
    }
}
