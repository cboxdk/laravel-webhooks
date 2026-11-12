<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\ValueObjects\Attempt;
use Cbox\Webhooks\ValueObjects\AttemptFilter;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * Read the HTTP attempts of one scope, across its deliveries and endpoints:
 * the request log a customer pages through. Scoped exactly like
 * {@see Endpoints}. (One delivery's attempts come with `Deliveries::find()`.)
 */
interface AttemptLog
{
    /**
     * Newest first by `started_at`. Pass the page's `nextCursor` back, with the
     * same scope and filter, for the next page.
     *
     * @return Page<Attempt>
     *
     * @throws InvalidCursor
     */
    public function paginate(Scope $scope, AttemptFilter $filter, ?string $cursor = null): Page;
}
