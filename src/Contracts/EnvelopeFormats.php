<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\InvalidEndpoint;

/**
 * The envelope formats an endpoint may choose from, by name. `cbox` and
 * `cloudevents` are registered by the package; a host adds its own from a
 * service provider. Deny by default: a name nobody registered cannot be
 * stored on an endpoint.
 */
interface EnvelopeFormats
{
    /** Last registration wins, so a host can replace a shipped format under its name. */
    public function register(string $name, EnvelopeFormat $format): void;

    public function has(string $name): bool;

    /**
     * @throws InvalidEndpoint when no format has that name
     */
    public function get(string $name): EnvelopeFormat;

    /** @return list<string> */
    public function names(): array;
}
