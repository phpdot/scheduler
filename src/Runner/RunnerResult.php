<?php

declare(strict_types=1);

/**
 * The evidence one execution leaves behind: how it ended and what it printed.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

final readonly class RunnerResult
{
    /**
     * @param RunnerOutcome $outcome How the execution ended
     * @param int|null $exitCode The command's exit code, null when signaled
     * @param int $durationMs How long the command ran
     * @param string $outputTail The tail of the command's combined output
     * @param string|null $error Why the run failed, when it did
     */
    public function __construct(
        public RunnerOutcome $outcome,
        public int|null $exitCode,
        public int $durationMs,
        public string $outputTail,
        public string|null $error,
    ) {}
}
