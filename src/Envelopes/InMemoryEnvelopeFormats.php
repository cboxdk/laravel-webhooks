<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Envelopes;

use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;

final class InMemoryEnvelopeFormats implements EnvelopeFormats
{
    /** @var array<string, EnvelopeFormat> */
    private array $formats = [];

    public function register(string $name, EnvelopeFormat $format): void
    {
        $this->formats[$name] = $format;
    }

    public function has(string $name): bool
    {
        return isset($this->formats[$name]);
    }

    public function get(string $name): EnvelopeFormat
    {
        return $this->formats[$name] ?? throw InvalidEndpoint::unknownEnvelope($name, $this->names());
    }

    public function names(): array
    {
        return array_keys($this->formats);
    }
}
