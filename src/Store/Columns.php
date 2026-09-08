<?php

declare(strict_types=1);

/**
 * Narrowing database rows to typed values: drivers hand back strings, ints,
 * or bools for the same column, and a blind cast off mixed hides a corrupt
 * row instead of naming it.
 *
 * @internal
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Store;

final class Columns
{
    /**
     * @param array<string, mixed> $row The row being read
     * @param string $key The column key
     *
     * @return string The column as text, empty when absent or null.
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $row The row being read
     * @param string $key The column key
     *
     * @return string|null The column as text, null when absent or null.
     */
    public static function nullableString(array $row, string $key): null|string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row The row being read
     * @param string $key The column key
     *
     * @return int The column as an integer, 0 when absent or non-numeric.
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row The row being read
     * @param string $key The column key
     *
     * @return int|null The column as an integer, null when absent or non-numeric.
     */
    public static function nullableInt(array $row, string $key): null|int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $row The row being read
     * @param string $key The column key
     *
     * @return bool True for truthy column shapes across drivers.
     */
    public static function bool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return $value === true || $value === 1 || $value === '1';
    }
}
