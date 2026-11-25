<?php

declare(strict_types=1);

/**
 * Polls for a process to stop running. A killed process lingers as a zombie
 * until its parent reaps it, and a container's PID 1 never does, so the
 * process table is read for a live state rather than for presence.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Tests\Support;

use Symfony\Component\Process\Process;

trait AwaitsExit
{
    /**
     * @param int $pid The process waited for
     * @param int $milliseconds How long to wait at most
     *
     * @return bool True once the process is no longer running.
     */
    private function exitsWithin(int $pid, int $milliseconds = 2000): bool
    {
        for ($waited = 0; $waited < $milliseconds; $waited += 50) {
            if ($pid <= 0 || !$this->isRunning($pid)) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }

    /**
     * @param int $pid The process examined
     *
     * @return bool True while it exists and has not become a zombie.
     */
    private function isRunning(int $pid): bool
    {
        if (!posix_kill($pid, 0)) {
            return false;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        if (is_string($stat)) {
            $close = strrpos($stat, ')');

            return $close !== false && substr($stat, $close + 2, 1) !== 'Z';
        }

        $state = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
        $state->run();

        return $state->isSuccessful() && !str_starts_with(trim($state->getOutput()), 'Z');
    }
}
