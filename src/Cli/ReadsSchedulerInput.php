<?php

declare(strict_types=1);

/**
 * Reading raw console input as the typed values the scheduler takes: narrow
 * checks, absent or malformed values returned as absent, never a blind cast.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use Symfony\Component\Console\Input\InputInterface;

trait ReadsSchedulerInput
{
    /**
     * @param InputInterface $input The console input
     * @param string $name The argument name
     *
     * @return string The argument value, empty when malformed.
     */
    protected function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @param InputInterface $input The console input
     * @param string $name The option name
     *
     * @return string|null The trimmed option, null when absent or empty.
     */
    protected function textOption(InputInterface $input, string $name): null|string
    {
        $value = $input->getOption($name);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param InputInterface $input The console input
     * @param string $name The option name
     *
     * @return int|null The numeric option, null when absent or malformed.
     */
    protected function intOption(InputInterface $input, string $name): null|int
    {
        $value = $input->getOption($name);

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param InputInterface $input The console input
     * @param string $name The option name
     *
     * @return bool True only when the flag was passed.
     */
    protected function flagOption(InputInterface $input, string $name): bool
    {
        return $input->getOption($name) === true;
    }
}
