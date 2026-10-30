<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * RFC 9110 §10.2.3: `Retry-After` is either delay-seconds or an HTTP-date. The
 * caller caps the result, because a receiver can ask for anything.
 */
final class RetryAfter
{
    public static function seconds(?string $header, CarbonImmutable $now): ?int
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::RFC7231, $header, new DateTimeZone('GMT'));

        if ($date === false) {
            $timestamp = strtotime($header);

            if ($timestamp === false) {
                return null;
            }

            $date = (new DateTimeImmutable('@'.$timestamp));
        }

        return max(0, $date->getTimestamp() - $now->getTimestamp());
    }
}
