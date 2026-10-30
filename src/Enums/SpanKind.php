<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * The package's own span kinds, so the delivery code can name one without a
 * dependency on a tracing library; the telemetry adapter maps them.
 */
enum SpanKind: string
{
    case Internal = 'internal';
    case Client = 'client';
    case Server = 'server';
}
