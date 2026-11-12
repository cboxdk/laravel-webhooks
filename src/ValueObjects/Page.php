<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

/**
 * One page of a keyset-paginated listing, newest first.
 *
 * `nextCursor` is an opaque string: hand it back to the same method with the
 * same scope and filter to get the following page, and stop when it is null.
 * A cursor names a position, not a page number, so rows written while a
 * caller pages through are neither skipped nor repeated.
 *
 * The zero-argument form is a valid, empty last page so a test double can
 * return one.
 *
 * @template-covariant T
 */
readonly class Page
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items = [],
        public ?string $nextCursor = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
