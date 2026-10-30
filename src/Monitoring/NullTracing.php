<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Monitoring;

use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Enums\SpanKind;
use Closure;

/** No tracer: run the callback, propagate nothing. */
final class NullTracing implements Tracing
{
    public function span(string $name, SpanKind $kind, array $attributes, Closure $callback): mixed
    {
        return $callback(new SpanContext);
    }

    public function propagationHeaders(): array
    {
        return [];
    }

    public function continueTrace(?string $traceparent): void {}
}
