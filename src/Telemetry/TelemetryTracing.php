<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Telemetry;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;
use Cbox\Telemetry\Tracing\SpanKind as TelemetrySpanKind;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Monitoring\SpanContext;
use Closure;

/**
 * Spans and W3C trace propagation through laravel-telemetry. The deliverer's
 * span is a client span whose `traceparent` rides on the request; the
 * receiver's middleware continues it, so the sender's publish and the
 * receiver's handling are one trace end to end.
 */
final readonly class TelemetryTracing implements Tracing
{
    public function __construct(private TelemetryManager $telemetry) {}

    public function span(string $name, SpanKind $kind, array $attributes, Closure $callback): mixed
    {
        return $this->telemetry->span(
            $name,
            static fn (Span $span): mixed => $callback(new SpanContext(static function (string $key, int|float|string|bool|null $value) use ($span): void {
                $span->setAttribute($key, $value);
            })),
            $attributes,
            match ($kind) {
                SpanKind::Client => TelemetrySpanKind::Client,
                SpanKind::Server => TelemetrySpanKind::Server,
                SpanKind::Internal => TelemetrySpanKind::Internal,
            },
        );
    }

    public function propagationHeaders(): array
    {
        $traceparent = $this->telemetry->traceparent();

        return $traceparent === null ? [] : ['traceparent' => $traceparent];
    }

    public function continueTrace(?string $traceparent): void
    {
        $this->telemetry->continueTrace($traceparent);
    }
}
