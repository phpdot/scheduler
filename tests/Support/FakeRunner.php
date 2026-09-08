<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Support;

use Closure;
use PHPdot\Scheduler\Contract\TaskRunnerInterface;
use PHPdot\Scheduler\Runner\RunnerOutcome;
use PHPdot\Scheduler\Runner\RunnerResult;
use PHPdot\Scheduler\Runner\TaskSpec;

/**
 * A scripted runner: hands back queued results, records what it was asked to
 * run, and drives the engine's heartbeat callback once per poll.
 */
final class FakeRunner implements TaskRunnerInterface
{
    /** @var list<TaskSpec> */
    public array $tasks = [];

    /** @var list<RunnerResult> */
    public array $results = [];

    public int $stopped = 0;

    public function __construct()
    {
        $this->results = [new RunnerResult(RunnerOutcome::Completed, 0, 12, 'done', null)];
    }

    /**
     * @param RunnerResult $result The result the next run hands back
     */
    public function nextReturns(RunnerResult $result): void
    {
        $this->results = [$result];
    }

    public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult
    {
        $this->tasks[] = $task;

        if ($onPoll !== null) {
            ($onPoll)();
        }

        return array_shift($this->results) ?? new RunnerResult(RunnerOutcome::Completed, 0, 0, '', null);
    }

    public function stopAll(): void
    {
        $this->stopped++;
    }
}
