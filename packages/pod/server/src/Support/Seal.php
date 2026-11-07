<?php

namespace Fleetbase\Pod\Support;

/**
 * Tamper-evident hash of a finished record (DESIGN.md §7): SHA-256 over a
 * canonical JSON form (keys sorted at every level), so the same content always
 * gives the same hash and any later change is detectable.
 */
final class Seal
{
    public static function hash(array $record): string
    {
        return hash('sha256', self::canonical($record));
    }

    public static function canonical(array $record): string
    {
        return json_encode(self::sort($record), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_float($value) ? round($value, 6) : $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map([self::class, 'sort'], $value);
    }
}
