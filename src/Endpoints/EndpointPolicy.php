<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Delivery\Signer;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Cbox\Webhooks\Support\Config;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * What an endpoint may look like. Shared by the registry and the management
 * API's form requests, so the two cannot drift.
 */
final readonly class EndpointPolicy
{
    /** Headers the owner cannot set: the transport's, and every signature header any shipped scheme emits. */
    public const array RESERVED_HEADERS = [
        'host', 'content-type', 'content-length', 'transfer-encoding', 'connection', 'user-agent',
        'idempotency-key', 'traceparent', 'tracestate',
        'x-webhook-event', 'x-webhook-delivery', 'x-webhook-attempt', 'x-webhook-sequence',
        'x-cbox-signature', 'x-cbox-timestamp',
        'webhook-id', 'webhook-timestamp', 'webhook-signature', 'svix-id', 'svix-timestamp', 'svix-signature',
    ];

    public function __construct(
        private UrlGuard $guard,
        private EventCatalogs $catalogs,
        private Signer $signer,
        private Config $config,
        private EnvelopeFormats $envelopes,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedSchemes(): array
    {
        return $this->config->bool('allow_http', false) ? ['https', 'http'] : ['https'];
    }

    /**
     * @throws InvalidEndpoint
     */
    public function assertUrl(string $url): void
    {
        try {
            $this->guard->assertSafe($url, $this->allowedSchemes(), false);
        } catch (BlockedUrl) {
            throw InvalidEndpoint::unsafeUrl();
        }
    }

    /**
     * Every subscription must expand to a type the endpoint's own scope offers.
     *
     * @param  list<string>  $eventTypes
     *
     * @throws InvalidEndpoint
     * @throws UnknownEventType
     */
    public function assertEventTypes(array $eventTypes, Scope $scope): void
    {
        $types = array_values(array_filter($eventTypes, static fn (string $t): bool => trim($t) !== ''));

        if ($types === []) {
            throw InvalidEndpoint::noEventTypes();
        }

        $this->catalogs->for($scope)->expand($types);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @throws InvalidEndpoint
     */
    public function assertHeaders(array $headers): void
    {
        foreach (array_keys($headers) as $name) {
            if ($this->isReservedHeader($name)) {
                throw InvalidEndpoint::forbiddenHeader($name);
            }
        }
    }

    public function isReservedHeader(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::RESERVED_HEADERS, true);
    }

    /**
     * @throws InvalidEndpoint
     */
    public function assertScheme(string $scheme): void
    {
        if (! $this->signer->canSign($scheme)) {
            throw InvalidEndpoint::schemeCannotSign($scheme);
        }
    }

    /**
     * @throws InvalidEndpoint when no envelope format has that name
     */
    public function assertEnvelope(string $name): void
    {
        $this->envelopes->get($name);
    }

    /**
     * @return list<string>
     */
    public function envelopeNames(): array
    {
        return $this->envelopes->names();
    }

    /**
     * @throws InvalidEndpoint
     */
    public function assertBatch(int $size, int $windowSeconds): void
    {
        $maxSize = $this->maxBatchSize();
        $maxWindow = $this->maxBatchWindow();

        if ($size < 1 || $size > $maxSize || $windowSeconds < 0 || $windowSeconds > $maxWindow) {
            throw InvalidEndpoint::batchOutOfBounds($size, $windowSeconds, $maxSize, $maxWindow);
        }
    }

    /**
     * @throws InvalidEndpoint
     */
    public function assertRateLimit(?int $limit): void
    {
        if ($limit !== null && $limit < 1) {
            throw InvalidEndpoint::rateLimitOutOfBounds($limit);
        }
    }

    public function maxBatchSize(): int
    {
        return $this->config->positiveInt('batch.max_size', 100);
    }

    public function maxBatchWindow(): int
    {
        return max(0, $this->config->int('batch.max_window_seconds', 300));
    }
}
