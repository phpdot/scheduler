<?php

declare(strict_types=1);

/**
 * The store schema is absent or from another scheduler version.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

final class SchemaMismatchException extends SchedulerException
{
    /**
     * @param int|null $found The schema version the store reported, null when absent
     * @param int $expected The schema version this package was built for
     *
     * @return self
     */
    public static function found(null|int $found, int $expected): self
    {
        return new self(sprintf(
            'Scheduler schema version mismatch: store reports %s, this package expects %d. Run scheduler:install.',
            $found === null ? 'no schema' : (string) $found,
            $expected,
        ));
    }
}
