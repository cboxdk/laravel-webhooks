<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

/**
 * An endpoint specification the registry refuses: an unsafe or non-https URL, a
 * forbidden header, a scheme that cannot sign, batch or rate-limit bounds. The
 * message is safe to show the caller; it never reveals a resolved address.
 */
final class InvalidEndpoint extends WebhooksException
{
    public static function unsafeUrl(): self
    {
        return new self('The endpoint URL must be a public https URL.');
    }

    public static function forbiddenHeader(string $name): self
    {
        return new self(sprintf('Header [%s] cannot be set on an endpoint: it is reserved for the transport or the signature.', $name));
    }

    public static function schemeCannotSign(string $scheme): self
    {
        return new self(sprintf('Signature scheme [%s] is unknown or verify-only; an endpoint needs a scheme that can sign.', $scheme));
    }

    public static function batchOutOfBounds(int $size, int $window, int $maxSize, int $maxWindow): self
    {
        return new self(sprintf('batch_size must be 1..%d and batch_window_seconds 0..%d; got %d and %d.', $maxSize, $maxWindow, $size, $window));
    }

    public static function rateLimitOutOfBounds(int $limit): self
    {
        return new self(sprintf('rate_limit_per_minute must be at least 1 when set; got %d.', $limit));
    }

    /**
     * @param  list<string>  $known
     */
    public static function unknownEnvelope(string $name, array $known): self
    {
        return new self(sprintf('Envelope format [%s] is not registered; choose one of: %s.', $name, implode(', ', $known)));
    }

    public static function noEventTypes(): self
    {
        return new self('An endpoint must subscribe to at least one event type.');
    }

    public static function headerName(string $name): self
    {
        return new self(sprintf('Header name [%s] is not a valid HTTP token.', $name));
    }

    public static function headerValue(string $name, int $max): self
    {
        return new self(sprintf('The value of header [%s] must be at most %d characters with no line breaks.', $name, $max));
    }

    public static function tooManyHeaders(int $count, int $max): self
    {
        return new self(sprintf('An endpoint may set at most %d headers; got %d.', $max, $count));
    }

    public static function retryScheduleOutOfBounds(int $maxEntries, int $maxDelay): self
    {
        return new self(sprintf('retry_schedule must be at most %d entries, each 1..%d seconds.', $maxEntries, $maxDelay));
    }

    public static function timeoutOutOfBounds(int $timeout, int $max): self
    {
        return new self(sprintf('timeout_seconds must be 1..%d; got %d.', $max, $timeout));
    }

    public static function transformTooLong(int $max): self
    {
        return new self(sprintf('A transform must be 1..%d characters.', $max));
    }

    public static function invalidTransform(string $reason): self
    {
        return new self(sprintf('The transform is invalid: %s', $reason));
    }

    public static function noPayloadTransformer(): self
    {
        return new self('Transforms are not available: no payload transformer is configured. Bind Contracts\PayloadTransformer to enable them.');
    }
}
