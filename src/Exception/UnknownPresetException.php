<?php

declare(strict_types=1);

/**
 * A preset name the interface does not offer.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

use PHPdot\Scheduler\Schedule\Preset;

final class UnknownPresetException extends SchedulerException
{
    /**
     * @param string $preset The rejected preset name
     *
     * @return self
     */
    public static function forPreset(string $preset): self
    {
        return new self(sprintf('Unknown preset "%s"; available: %s.', $preset, Preset::names()));
    }
}
