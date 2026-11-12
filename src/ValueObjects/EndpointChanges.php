<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * A partial update: null means "leave as is" for every field, so a caller
 * states only what changes. Clearing a nullable column goes through the
 * explicit `clear*` flags, because null cannot mean both "untouched" and "unset".
 *
 * @param  list<string>|null  $eventTypes
 * @param  array<string, string>|null  $headers
 * @param  array<string, mixed>|null  $metadata
 */
final readonly class EndpointChanges
{
    /**
     * @param  list<string>|null  $eventTypes
     * @param  array<string, string>|null  $headers
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ?string $url = null,
        public ?string $description = null,
        public ?array $eventTypes = null,
        public ?int $batchSize = null,
        public ?int $batchWindowSeconds = null,
        public ?int $rateLimitPerMinute = null,
        public ?array $headers = null,
        public ?string $apiVersion = null,
        public ?array $metadata = null,
        public bool $clearRateLimit = false,
        public bool $clearApiVersion = false,
        public bool $clearDescription = false,
        public ?string $envelope = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->url === null && $this->description === null && $this->eventTypes === null
            && $this->batchSize === null && $this->batchWindowSeconds === null && $this->rateLimitPerMinute === null
            && $this->headers === null && $this->apiVersion === null && $this->metadata === null
            && ! $this->clearRateLimit && ! $this->clearApiVersion && ! $this->clearDescription
            && $this->envelope === null;
    }
}
