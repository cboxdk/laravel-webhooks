<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Enums;

/**
 * Why the engine disabled an endpoint on its own. The host turns these into the
 * message it sends the endpoint's owner.
 */
enum DisabledReason: string
{
    /** The receiver answered 410 Gone: it asked us to stop. */
    case Gone = 'gone';

    /** No success since the first failure for longer than `disable_after_seconds`. */
    case Unreachable = 'unreachable';
}
