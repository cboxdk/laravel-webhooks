<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * A host's erasure step for one person calls this to drop the events published
 * about them in one scope ({@see OutboundEvent::$subject}),
 * with the deliveries and attempts of those events. Events published without a
 * subject are not found by it.
 */
interface SubjectErasure
{
    /** @return int rows removed */
    public function forgetSubject(Scope $scope, string $subject): int;
}
