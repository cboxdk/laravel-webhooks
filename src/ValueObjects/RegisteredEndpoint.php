<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * A freshly registered endpoint with its plaintext secret, shown once. The
 * secret is never read back out of the store for display afterwards.
 */
readonly class RegisteredEndpoint
{
    public function __construct(
        public Endpoint $endpoint = new Endpoint,
        public string $secret = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->endpoint->toArray(), 'secret' => $this->secret];
    }
}
