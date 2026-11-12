<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * What the host adds to every request for an endpoint: who the sender says it
 * is, and headers that identify the host or the brand the endpoint's owner
 * signed up with. `new RequestProfile` adds nothing: the configured user agent,
 * no extra headers.
 */
final readonly class RequestProfile
{
    /**
     * @param  string|null  $userAgent  null keeps `webhooks.http.user_agent`, or the package's own
     * @param  array<string, string>  $headers  sent before the endpoint's own static headers, which win on a clash
     */
    public function __construct(
        public ?string $userAgent = null,
        public array $headers = [],
    ) {}
}
