<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\ValueObjects\EventFilter;
use Cbox\Webhooks\ValueObjects\Page;
use Cbox\Webhooks\ValueObjects\RecordedEvent;
use Cbox\Webhooks\ValueObjects\Scope;

/**
 * Read the events the outbox recorded, scoped exactly like {@see Endpoints}:
 * an id from another scope answers null, and a listing never leaves the scope.
 * Events live until retention removes them (`webhooks.retention.events_days`).
 */
interface EventLog
{
    public function find(Scope $scope, string $id): ?RecordedEvent;

    /**
     * Newest first by `occurred_at`. Pass the page's `nextCursor` back, with the
     * same scope and filter, for the next page.
     *
     * @return Page<RecordedEvent>
     *
     * @throws InvalidCursor
     */
    public function paginate(Scope $scope, EventFilter $filter, ?string $cursor = null): Page;
}
