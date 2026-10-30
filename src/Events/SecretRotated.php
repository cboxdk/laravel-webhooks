<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Events;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\ValueObjects\Endpoint;

/** A new secret was minted; the old one is accepted by the receiver until `previousSecretExpiresAt`. */
final readonly class SecretRotated
{
    public function __construct(
        public Endpoint $endpoint,
        public CarbonImmutable $previousSecretExpiresAt,
    ) {}
}
