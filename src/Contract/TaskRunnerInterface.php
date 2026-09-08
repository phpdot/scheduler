<?php

declare(strict_types=1);

/**
 * The execution seam: turns a claimed task into a result, and can stop
 * everything it started when the host is shutting down.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Contract;

use Closure;
use PHPdot\Scheduler\Runner\RunnerResult;
use PHPdot\Scheduler\Runner\TaskSpec;

interface TaskRunnerInterface
{
    /**
     * @param TaskSpec $task What to execute and under which limits
     * @param Closure|null $onPoll Invoked while the command runs, to renew leases
     *
     * @return RunnerResult How the execution ended.
     */
    public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult;

    /**
     * @return void
     */
    public function stopAll(): void;
}
