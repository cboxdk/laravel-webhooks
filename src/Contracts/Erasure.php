<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\ValueObjects\Scope;

/**
 * A host's erasure step calls this to drop everything the engine recorded
 * about one scope: events, deliveries and attempts. Endpoints are left for the
 * host to delete through {@see Endpoints::delete()}, since they are configuration
 * rather than personal data.
 */
interface Erasure
{
    /** @return int rows removed */
    public function forget(Scope $scope): int;
}
