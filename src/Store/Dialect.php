<?php

declare(strict_types=1);

/**
 * Per-driver SQL fragments for the claim protocol's time and conflict idioms.
 *
 * Every clock expression the store emits is evaluated by the database itself
 * in UTC, so lease math never depends on PHP servers' clocks being synced.
 *
 * @internal
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Store;

use PHPdot\Scheduler\Exception\UnsupportedDriverException;

final readonly class Dialect
{
    /**
     * @param string $driverName A phpdot/database driver name
     */
    public function __construct(
        private string $driverName,
    ) {
        if (!in_array($driverName, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw UnsupportedDriverException::forDriver($driverName);
        }
    }

    /**
     * @return string An expression yielding the store's UTC clock as text.
     */
    public function now(): string
    {
        return match ($this->driverName) {
            'mysql' => 'UTC_TIMESTAMP(6)',
            'pgsql' => "to_char(clock_timestamp() AT TIME ZONE 'utc', 'YYYY-MM-DD HH24:MI:SS.US')",
            default => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
        };
    }

    /**
     * @param int $seconds How far behind the store clock
     *
     * @return string An expression yielding the store's UTC clock minus the offset.
     */
    public function nowMinus(int $seconds): string
    {
        return match ($this->driverName) {
            'mysql' => sprintf('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $seconds),
            'pgsql' => sprintf(
                "to_char((clock_timestamp() AT TIME ZONE 'utc') - make_interval(secs => %d), 'YYYY-MM-DD HH24:MI:SS.US')",
                $seconds,
            ),
            default => sprintf("strftime('%%Y-%%m-%%d %%H:%%M:%%f', 'now', '-%d seconds')", $seconds),
        };
    }

    /**
     * @param int $seconds How far ahead of the store clock
     *
     * @return string An expression yielding the store's UTC clock plus the offset.
     */
    public function nowPlus(int $seconds): string
    {
        return match ($this->driverName) {
            'mysql' => sprintf('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL %d SECOND)', $seconds),
            'pgsql' => sprintf(
                "to_char((clock_timestamp() AT TIME ZONE 'utc') + make_interval(secs => %d), 'YYYY-MM-DD HH24:MI:SS.US')",
                $seconds,
            ),
            default => sprintf("strftime('%%Y-%%m-%%d %%H:%%M:%%f', 'now', '+%d seconds')", $seconds),
        };
    }

    /**
     * A conflict-suffix making an INSERT a no-op when the conflicting row
     * already exists.
     *
     * @param string ...$columns The unique columns the conflict targets
     *
     * @return string The INSERT suffix.
     */
    public function insertNoopConflict(string ...$columns): string
    {
        return match ($this->driverName) {
            'mysql' => sprintf(' ON DUPLICATE KEY UPDATE %1$s = %1$s', $columns[0]),
            default => sprintf(' ON CONFLICT (%s) DO NOTHING', implode(', ', $columns)),
        };
    }

    /**
     * A statement locking one schedule row for the claim transaction. SQLite
     * has no FOR UPDATE but serializes writers at the file level anyway.
     *
     * @param string $table The schedules table
     *
     * @return string A SELECT ... FOR UPDATE statement with one id binding.
     */
    public function lockScheduleRow(string $table): string
    {
        $suffix = $this->driverName === 'sqlite' ? '' : ' FOR UPDATE';

        return sprintf('SELECT id FROM %s WHERE id = ?%s', $table, $suffix);
    }
}
