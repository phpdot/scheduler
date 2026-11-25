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
use Symfony\Component\Console\Output\OutputInterface;

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
     * Read integer options by name, each taking its default when absent; a
     * malformed value is reported once and answers null, so no typo quietly
     * becomes a default.
     *
     * @param array<string, int|null> $defaults The value each option takes when absent
     *
     * @return array<string, int|null>|null The values by name, null when one was malformed.
     */
    protected function intOptions(InputInterface $input, OutputInterface $output, array $defaults): array|null
    {
        $values = [];

        foreach ($defaults as $name => $default) {
            $given = $input->getOption($name);

            if (!is_string($given) || trim($given) === '') {
                $values[$name] = $default;

                continue;
            }

            $given = trim($given);

            if (preg_match('/^-?\d+$/', $given) !== 1) {
                $this->error($output, sprintf('Option "--%s" needs a whole number, not "%s".', $name, $given));

                return null;
            }

            $values[$name] = (int) $given;
        }

        return $values;
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
