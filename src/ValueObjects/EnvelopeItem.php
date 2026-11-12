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
    ) {}
}
