<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Support;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Exceptions\InvalidCursor;
use Cbox\Webhooks\ValueObjects\Page;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Keyset pagination, newest first, over a timestamp column with the id as the
 * tie-breaker. The ids are ULIDs, so the pair is unique and its order is
 * stable; the timestamp leads because that is what the per-scope indexes are
 * built on.
 *
 * A cursor is the (timestamp, id) of the last row of the page it came from,
 * base64url-encoded. It is a position, not a secret and not an authorization:
 * every query it is used in is still filtered by the caller's scope, so a
 * cursor taken from another scope finds nothing.
 */
final class Keyset
{
    public const int MAX_PAGE_SIZE = 1000;

    /**
     * @template TModel of Model
     * @template TItem
     *
     * @param  Builder<TModel>  $query  already scoped and filtered, not yet ordered
     * @param  Closure(TModel): (CarbonImmutable|null)  $timestamp  the row's value of `$column`
     * @param  Closure(TModel): TItem  $map
     * @return Page<TItem>
     *
     * @throws InvalidCursor
     */
    public static function page(Builder $query, string $column, int $limit, ?string $cursor, Closure $timestamp, Closure $map): Page
    {
        $size = max(1, min(self::MAX_PAGE_SIZE, $limit));

        if ($cursor !== null && $cursor !== '') {
            [$at, $id] = self::decode($cursor);

            $query->where(static function (Builder $q) use ($column, $at, $id): void {
                $q->where($column, '<', $at)
                    ->orWhere(static function (Builder $tie) use ($column, $at, $id): void {
                        $tie->where($column, $at)->where('id', '<', $id);
                    });
            });
        }

        // One more than the page: its presence is what says there is a next page.
        $rows = array_values($query->orderByDesc($column)->orderByDesc('id')->limit($size + 1)->get()->all());

        $next = null;

        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $last = $rows[$size - 1];
            $at = $timestamp($last);
            $key = $last->getKey();

            if ($at !== null && is_string($key)) {
                $next = self::encode($at, $key);
            }
        }

        return new Page(array_map($map, $rows), $next);
    }

    public static function encode(CarbonImmutable $at, string $id): string
    {
        return rtrim(strtr(base64_encode(Json::encode(['t' => $at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'i' => $id])), '+/', '-_'), '=');
    }

    /**
     * @return array{0: CarbonImmutable, 1: string}
     *
     * @throws InvalidCursor
     */
    public static function decode(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $decoded = $json === false ? null : Json::object($json);

        $at = $decoded['t'] ?? null;
        $id = $decoded['i'] ?? null;

        if (! is_string($at) || ! is_string($id) || $id === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $at) !== 1) {
            throw InvalidCursor::make();
        }

        try {
            return [CarbonImmutable::parse($at), $id];
        } catch (Throwable) {
            throw InvalidCursor::make();
        }
    }
}
