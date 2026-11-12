<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * What one HTTP attempt came to. Only `Success`, `HttpError`, `Timeout` and
 * `ConnectionError` say anything about the receiver's health; the rest are this
 * side's decisions (a refused URL, an open breaker, a rate limit) and never feed
 * the circuit breaker.
 */
enum AttemptOutcome: string
{
    case Success = 'success';
    case HttpError = 'http_error';
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case BlockedUrl = 'blocked_url';
    case BreakerOpen = 'breaker_open';
    case RateLimited = 'rate_limited';
    case Cancelled = 'cancelled';

    /** The endpoint's transform threw or gave null: permanent, no retry, never the receiver's fault. */
    case TransformFailed = 'transform_failed';

    /** Left pending without an attempt because the endpoint is paused. Never written as an attempt row. */
    case Deferred = 'deferred';

    public function isSuccess(): bool
    {
        return $this === self::Success;
    }

    /** A failure the receiver is responsible for, which counts against its breaker. */
    public function countsAgainstEndpoint(): bool
    {
        return match ($this) {
            self::HttpError, self::Timeout, self::ConnectionError => true,
            default => false,
        };
    }
}
