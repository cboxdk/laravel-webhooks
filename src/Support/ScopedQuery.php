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
 * partition-wide and global rows.
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
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function receiving(Builder $query, Scope $event): Builder
    {
        return $query
            ->where(function (Builder $q) use ($event): void {
                $q->whereNull('partition');

                if ($event->partition !== null) {
                    $q->orWhere('partition', $event->partition);
                }
            })
            ->where(function (Builder $q) use ($event): void {
                $q->whereNull('scope_key');

                if ($event->key !== null) {
                    $q->orWhere('scope_key', $event->key);
                }
            });
    }
}
