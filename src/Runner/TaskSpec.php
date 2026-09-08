<?php

declare(strict_types=1);

/**
 * What a claimed run executes, and under which hard limits.
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
     */
    public function __construct(
        public string $command,
        public int $timeoutSeconds,
        public int|null $idleTimeoutSeconds,
        public string|null $cwd,
        public array $env = [],
    ) {}
}
