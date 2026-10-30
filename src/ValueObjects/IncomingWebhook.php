<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * One verified, parsed inbound item as a handler sees it. `raw` is the item's
 * envelope as received, for a handler that needs a field this VO does not name.
 *
 * The zero-argument form is valid so a handler can be unit-tested with a
 * hand-built instance.
 */
readonly class IncomingWebhook
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id = '',
        public string $type = '',
        public ?CarbonImmutable $occurredAt = null,
        public string $deliveryId = '',
        public ?int $sequence = null,
        public Scope $scope = new Scope(null, null),
        public ?string $apiVersion = null,
        public array $data = [],
        public string $endpointName = '',
        public array $raw = [],
        public ?int $attempt = null,
    ) {}
}
