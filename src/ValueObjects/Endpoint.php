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
 *
 * `headers` holds the values opened, because the host may need them (to
 * show a masked form, or to copy an endpoint). {@see toArray()} masks them,
 * so a serialised endpoint never carries a header's secret.
 *
 * `retrySchedule` and `timeoutSeconds` are null when the endpoint uses the
 * configured default; an empty schedule is one try, no retries.
 */
readonly class Endpoint
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     * @param  list<int>|null  $retrySchedule
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
        public ?array $retrySchedule = null,
        public ?int $timeoutSeconds = null,
        public bool $followRedirects = false,
        public ?string $transform = null,
    ) {}

    public function isBatching(): bool
    {
        return $this->batchSize > 1;
    }

    /**
     * The header values cut to a short prefix and an ellipsis: enough to tell
     * two tokens apart, never enough to use one. At most a third of a value,
     * and at most twelve characters, is shown.
     *
     * @return array<string, string>
     */
    public function maskedHeaders(): array
    {
        $out = [];

        foreach ($this->headers as $name => $value) {
            $out[$name] = mb_substr($value, 0, min(12, intdiv(mb_strlen($value), 3))).'…';
        }

        return $out;
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
            'headers' => $this->maskedHeaders(),
            'api_version' => $this->apiVersion,
            'retry_schedule' => $this->retrySchedule,
            'timeout_seconds' => $this->timeoutSeconds,
            'follow_redirects' => $this->followRedirects,
            'transform' => $this->transform,
            'last_sequence' => $this->lastSequence,
            'previous_secret_expires_at' => $this->previousSecretExpiresAt?->toIso8601String(),
            'health' => $this->health->toArray(),
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
