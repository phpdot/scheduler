<?php

declare(strict_types=1);

/**
 * What a claimed run executes, under which hard limits, and for which fire:
 * an adopted run learns the tick it revives rather than the moment it runs.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

final readonly class TaskSpec
{
    /**
     * @param string $command The shell command to execute
     * @param int $timeoutSeconds Hard wall-clock cap on the command
     * @param int|null $idleTimeoutSeconds Cap on the command producing no output
     * @param string|null $cwd Working directory, null for the runner's own
     * @param array<string, string> $env Extra environment for the command
     * @param string|null $scheduleId The schedule the fire belongs to, null outside the engine
     * @param string|null $tick The fire being executed, null outside the engine
     * @param int $attempt Which attempt at this fire the run is
     */
    public function __construct(
        public string $command,
        public int $timeoutSeconds,
        public int|null $idleTimeoutSeconds,
        public string|null $cwd,
        public array $env = [],
        public string|null $scheduleId = null,
        public string|null $tick = null,
        public int $attempt = 1,
    ) {}
}
