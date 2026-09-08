<?php

declare(strict_types=1);

/**
 * Scheduler configuration that fails its own coherence rules.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

final class InvalidConfigurationException extends SchedulerException
{
    /**
     * @param string $field The offending setting
     * @param string $reason Why the value was rejected
     *
     * @return self
     */
    public static function forField(string $field, string $reason): self
    {
        return new self(sprintf('Invalid scheduler configuration "%s": %s.', $field, $reason));
    }
}
