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
}
