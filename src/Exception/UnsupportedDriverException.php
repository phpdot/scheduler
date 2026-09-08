<?php

declare(strict_types=1);

/**
 * The connection's database driver has no scheduler dialect.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

final class UnsupportedDriverException extends SchedulerException
{
    /**
     * @param string $driver The unsupported driver name
     *
     * @return self
     */
    public static function forDriver(string $driver): self
    {
        return new self(sprintf(
            'The scheduler store has no dialect for driver "%s"; supported: mysql, pgsql, sqlite.',
            $driver,
        ));
    }
}
