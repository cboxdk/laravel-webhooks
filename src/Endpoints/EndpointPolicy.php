<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Endpoints;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\EventCatalogs;
use Cbox\Webhooks\Contracts\PayloadTransformer;
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
        'host', 'content-type', 'content-length', 'content-encoding', 'transfer-encoding', 'connection', 'user-agent',
        'idempotency-key', 'traceparent', 'tracestate',
        'x-webhook-event', 'x-webhook-delivery', 'x-webhook-attempt', 'x-webhook-sequence',
        'x-cbox-signature', 'x-cbox-timestamp',
        'webhook-id', 'webhook-timestamp', 'webhook-signature', 'svix-id', 'svix-timestamp', 'svix-signature',
    ];

    /** Name prefixes the owner cannot use: this package's, the signature schemes' and CloudEvents' binary-mode attributes. */
    public const array RESERVED_HEADER_PREFIXES = ['webhook-', 'x-webhook-', 'x-cbox-', 'ce-'];

    public const int MAX_HEADERS = 10;

    public const int MAX_HEADER_VALUE_LENGTH = 1000;

    public const int MAX_RETRY_ENTRIES = 20;

    public const int MAX_RETRY_DELAY_SECONDS = 604_800;

    /** The ceiling on an endpoint's own timeout; `http.max_endpoint_timeout` may lower it. */
    public const int MAX_TIMEOUT_SECONDS = 120;

    public const int MAX_TRANSFORM_LENGTH = 4000;

    public function __construct(
        private UrlGuard $guard,
        private EventCatalogs $catalogs,
        private Signer $signer,
        private Config $config,
        private EnvelopeFormats $envelopes,
        private PayloadTransformer $transformer,
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
     * At most ten headers; each name an RFC 9110 token that is not reserved,
     * each value at most 1000 characters with no CR, LF or NUL (a line break
     * in a value is how a header is smuggled into a request).
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidEndpoint
     */
    public function assertHeaders(array $headers): void
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw InvalidEndpoint::tooManyHeaders(count($headers), self::MAX_HEADERS);
        }

        foreach ($headers as $name => $value) {
            // PHP turns a numeric string key ("123", a valid token) into an int.
            $name = (string) $name;

            if (! $this->isHeaderToken($name)) {
                throw InvalidEndpoint::headerName($name);
            }

            if ($this->isReservedHeader($name)) {
                throw InvalidEndpoint::forbiddenHeader($name);
            }

            if (! $this->isHeaderValue($value)) {
                throw InvalidEndpoint::headerValue($name, self::MAX_HEADER_VALUE_LENGTH);
            }
        }
    }

    public function isReservedHeader(string $name): bool
    {
        $name = strtolower(trim($name));

        if (in_array($name, self::RESERVED_HEADERS, true)) {
            return true;
        }

        foreach (self::RESERVED_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** An HTTP field name: one or more `tchar` (RFC 9110, section 5.6.2). */
    public function isHeaderToken(string $name): bool
    {
        return preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) === 1;
    }

    public function isHeaderValue(string $value): bool
    {
        return mb_strlen($value) <= self::MAX_HEADER_VALUE_LENGTH && strpbrk($value, "\r\n\0") === false;
    }

    /**
     * @param  list<int>|null  $schedule
     *
     * @throws InvalidEndpoint
     */
    public function assertRetrySchedule(?array $schedule): void
    {
        if ($schedule === null) {
            return;
        }

        if (count($schedule) > self::MAX_RETRY_ENTRIES) {
            throw InvalidEndpoint::retryScheduleOutOfBounds(self::MAX_RETRY_ENTRIES, self::MAX_RETRY_DELAY_SECONDS);
        }

        foreach ($schedule as $delay) {
            if ($delay < 1 || $delay > self::MAX_RETRY_DELAY_SECONDS) {
                throw InvalidEndpoint::retryScheduleOutOfBounds(self::MAX_RETRY_ENTRIES, self::MAX_RETRY_DELAY_SECONDS);
            }
        }
    }

    /**
     * @throws InvalidEndpoint
     */
    public function assertTimeout(?int $seconds): void
    {
        $max = $this->maxTimeout();

        if ($seconds !== null && ($seconds < 1 || $seconds > $max)) {
            throw InvalidEndpoint::timeoutOutOfBounds($seconds, $max);
        }
    }

    /** The highest timeout an endpoint may choose: 120 seconds, or less when the host says so. */
    public function maxTimeout(): int
    {
        return min(self::MAX_TIMEOUT_SECONDS, $this->config->positiveInt('http.max_endpoint_timeout', self::MAX_TIMEOUT_SECONDS));
    }

    /**
     * The bound transformer decides whether the expression is valid; with
     * none bound, every expression is refused.
     *
     * @throws InvalidEndpoint
     */
    public function assertTransform(?string $expression): void
    {
        if ($expression === null) {
            return;
        }

        if (trim($expression) === '' || mb_strlen($expression) > self::MAX_TRANSFORM_LENGTH) {
            throw InvalidEndpoint::transformTooLong(self::MAX_TRANSFORM_LENGTH);
        }

        $this->transformer->validate($expression);
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
