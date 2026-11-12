<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Support;

use JsonException;

/**
 * JSON at the package's serialization edges. Bodies are encoded once and the
 * exact bytes are what gets signed and sent, so there is one encoder with one
 * flag set.
 */
final class Json
{
    /**
     * @param  array<string, mixed>  $value
     *
     * @throws JsonException
     */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A JSON array of objects, with the same flags as {@see encode()}.
     *
     * @param  list<array<string, mixed>>  $values
     *
     * @throws JsonException
     */
    public static function encodeList(array $values): string
    {
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The body as a JSON object, or null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    public static function object(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Shape a stored JSON column as a string-keyed array. Columns are written by
     * this package only, so anything else is corruption and yields an empty array.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * A stored JSON list of strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * A stored JSON map of string to string (an endpoint's static headers).
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        $out = [];

        foreach (self::map($value) as $key => $item) {
            if (is_string($item)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
