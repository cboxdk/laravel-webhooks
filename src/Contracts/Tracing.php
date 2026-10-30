<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Monitoring\SpanContext;
use Closure;

/**
 * The seam the deliverer and the receiver use for spans and trace propagation.
 * `NullTracing` runs the callback and propagates nothing; the telemetry adapter
 * opens a real span and carries the W3C traceparent across the wire so the
 * receiver's handling continues the sender's trace.
 */
interface Tracing
{
    /**
     * @template T
     *
     * @param  array<string, scalar|null>  $attributes
     * @param  Closure(SpanContext): T  $callback
     * @return T
     */
    public function span(string $name, SpanKind $kind, array $attributes, Closure $callback): mixed;

    /**
     * Headers to add to an outbound request so the receiver can continue the trace.
     *
     * @return array<string, string>
     */
    public function propagationHeaders(): array;

    /** Continue from an inbound request's `traceparent`, if any. */
    public function continueTrace(?string $traceparent): void;
}
