<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The outcome of a rotation: the new plaintext secret (shown once) and when the
 * old one stops being accepted. The deliverer signs with the new secret from
 * the next attempt on; the receiver keeps both configured until the overlap ends.
 */
readonly class RotatedSecret
{
    public function __construct(
        public Endpoint $endpoint = new Endpoint,
        public string $secret = '',
        public ?CarbonImmutable $previousSecretExpiresAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->endpoint->toArray(),
            'secret' => $this->secret,
            'previous_secret_expires_at' => $this->previousSecretExpiresAt?->toIso8601String(),
        ];
    }
}
