<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\DisabledReason;
use Cbox\Webhooks\Enums\EndpointStatus;

/**
 * An endpoint as the API and the host see it: never the model, never the
 * secret. The zero-argument form is a valid, empty endpoint so a test double
 * can return one without reflection.
 */
readonly class Endpoint
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id = '',
        public Scope $scope = new Scope(null, null),
        public string $url = '',
        public array $eventTypes = [],
        public string $scheme = 'cbox',
        public EndpointStatus $status = EndpointStatus::Active,
        public ?string $description = null,
        public ?DisabledReason $disabledReason = null,
        public ?CarbonImmutable $disabledAt = null,
        public int $batchSize = 1,
        public int $batchWindowSeconds = 0,
        public ?int $rateLimitPerMinute = null,
        public array $headers = [],
        public ?string $apiVersion = null,
        public int $lastSequence = 0,
        public ?CarbonImmutable $previousSecretExpiresAt = null,
        public EndpointHealth $health = new EndpointHealth,
        public array $metadata = [],
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
        public string $envelope = 'cbox',
    ) {}

    public function isBatching(): bool
    {
        return $this->batchSize > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->toArray(),
            'url' => $this->url,
            'description' => $this->description,
            'event_types' => $this->eventTypes,
            'signature_scheme' => $this->scheme,
            'envelope' => $this->envelope,
            'status' => $this->status->value,
            'disabled_reason' => $this->disabledReason?->value,
            'disabled_at' => $this->disabledAt?->toIso8601String(),
            'batch_size' => $this->batchSize,
            'batch_window_seconds' => $this->batchWindowSeconds,
            'rate_limit_per_minute' => $this->rateLimitPerMinute,
            'headers' => $this->headers,
            'api_version' => $this->apiVersion,
            'last_sequence' => $this->lastSequence,
            'previous_secret_expires_at' => $this->previousSecretExpiresAt?->toIso8601String(),
            'health' => $this->health->toArray(),
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
