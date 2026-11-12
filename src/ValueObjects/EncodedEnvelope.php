<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * A request body ready to send: the exact bytes, and the content type that
 * describes them. The deliverer signs `body` and sends `body`; nothing
 * re-encodes it in between, so the signature covers what the receiver reads.
 */
final readonly class EncodedEnvelope
{
    public function __construct(
        public string $body,
        public string $contentType = 'application/json',
    ) {}
}
