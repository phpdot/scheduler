<?php

declare(strict_types=1);

/**
 * How a finished execution is written back to the run record.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Run;

final readonly class RunOutcome
{
    /**
     * @param RunState $state The terminal state to record
     * @param int|null $exitCode The command's exit code
     * @param int|null $durationMs How long the command ran
     * @param string|null $outputTail The tail of the command's combined output
     * @param string|null $error Why the run failed, when it did
     */
    public function __construct(
        public RunState $state,
        public int|null $exitCode,
        public int|null $durationMs,
        public string|null $outputTail,
        public string|null $error,
    ) {}
}
