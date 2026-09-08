<?php

declare(strict_types=1);

/**
 * How an execution ended, before it is written back as run state.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

enum RunnerOutcome: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Interrupted = 'interrupted';
}
