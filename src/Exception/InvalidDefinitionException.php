<?php

declare(strict_types=1);

/**
 * A schedule definition that failed validation before it could be stored.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

final class InvalidDefinitionException extends SchedulerException
{
    /**
     * @param string $field The offending field
     * @param string $reason Why the value was rejected
     *
     * @return self
     */
    public static function forField(string $field, string $reason): self
    {
        return new self(sprintf('Invalid schedule definition field "%s": %s.', $field, $reason));
    }
}
