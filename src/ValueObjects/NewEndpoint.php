<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * Everything the registry needs to create an endpoint. The scope is passed
 * beside it, through the method that names the scope's shape, never inside it.
 *
 * The delivery policy is optional: a null `retrySchedule` or `timeoutSeconds`
 * uses the configured default, and an empty schedule means one try, no
 * retries. `headers` are stored sealed. `transform` is an expression for the
 * host's bound PayloadTransformer, validated by it on register.
 */
final readonly class NewEndpoint
{
    /**
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $metadata
     * @param  list<int>|null  $retrySchedule  seconds between attempts
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
        public string $envelope = 'cbox',
        public ?array $retrySchedule = null,
        public ?int $timeoutSeconds = null,
        public bool $followRedirects = false,
        public ?string $transform = null,
    ) {}
}
