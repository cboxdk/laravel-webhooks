<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Closure;

/**
 * What a span callback may do to its span: set attributes after the fact (the
 * response code, the outcome). A closure rather than a tracing library's span,
 * so the delivery code never names one.
 */
final class SpanContext
{
    /** @var array<string, scalar|null> */
    private array $attributes = [];

    /**
     * @param  (Closure(string, scalar|null): void)|null  $sink
     */
    public function __construct(private readonly ?Closure $sink = null) {}

    public function set(string $key, int|float|string|bool|null $value): void
    {
        $this->attributes[$key] = $value;

        if ($this->sink !== null) {
            ($this->sink)($key, $value);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
