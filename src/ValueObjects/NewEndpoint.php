<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * Everything the registry needs to create an endpoint. The scope is passed
 * beside it, through the method that names the scope's shape, never inside it.
 */
final readonly class NewEndpoint
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $url,
        public array $eventTypes,
        public string $scheme = 'cbox',
        public ?string $description = null,
        public int $batchSize = 1,
        public int $batchWindowSeconds = 0,
        public ?int $rateLimitPerMinute = null,
        public array $headers = [],
        public ?string $apiVersion = null,
        public array $metadata = [],
    ) {}
}
