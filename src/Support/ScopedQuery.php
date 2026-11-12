<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Support;

use Cbox\Webhooks\ValueObjects\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The two predicates the package uses on scoped rows.
 *
 * `exact()` is the caller's view: a row belongs to the scope only when both
 * columns match, nulls included, so an id learned from another scope is a
 * 404 rather than a leak. `receiving()` is the fan-out view: the endpoints
 * whose scope covers an event's scope, which is the exact owner plus the
 * partition-wide rows of the event's partition and the global rows.
 */
final class ScopedQuery
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function exact(Builder $query, Scope $scope): Builder
    {
        return $query
            ->where(fn (Builder $q) => $scope->partition === null ? $q->whereNull('partition') : $q->where('partition', $scope->partition))
            ->where(fn (Builder $q) => $scope->key === null ? $q->whereNull('scope_key') : $q->where('scope_key', $scope->key));
    }

    /**
     * The SQL form of {@see Scope::matches()}; a test holds the two together.
     *
     * An owner row (it has a key) receives only its exact scope: the partition
     * is part of the identity, so a row with no partition does not receive an
     * event that has one, and the other way round. A wide row (no key) receives
     * its own partition, or everything when it has no partition either.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function receiving(Builder $query, Scope $event): Builder
    {
        return $query->where(function (Builder $q) use ($event): void {
            $q->where(function (Builder $wide) use ($event): void {
                $wide->whereNull('scope_key')->where(function (Builder $partition) use ($event): void {
                    $partition->whereNull('partition');

                    if ($event->partition !== null) {
                        $partition->orWhere('partition', $event->partition);
                    }
                });
            });

            if ($event->key !== null) {
                $q->orWhere(function (Builder $owner) use ($event): void {
                    $owner->where('scope_key', $event->key);

                    $event->partition === null
                        ? $owner->whereNull('partition')
                        : $owner->where('partition', $event->partition);
                });
            }
        });
    }
}
