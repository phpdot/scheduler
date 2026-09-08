<?php

declare(strict_types=1);

/**
 * A schedule pattern that could not be parsed as a cron expression or interval.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

final class InvalidPatternException extends SchedulerException
{
    /**
     * @param string $expression The rejected pattern
     * @param string $reason Why the expression was rejected
     *
     * @return self
     */
    public static function forExpression(string $expression, string $reason): self
    {
        return new self(sprintf('Invalid schedule pattern "%s": %s.', $expression, $reason));
    }
}
