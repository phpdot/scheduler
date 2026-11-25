<?php

declare(strict_types=1);

/**
 * Executes claimed tasks through symfony/process under the safety contract:
 * a hard timeout on every command, escalation from SIGTERM to SIGKILL across
 * the whole process tree, and a stop-everything path for host shutdown. Each
 * command is told its schedule, tick and attempt through the environment.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

use Closure;
use PHPdot\Scheduler\Contract\TaskRunnerInterface;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessRunner implements TaskRunnerInterface
{
    private const int POLL_MICROSECONDS = 50000;

    private const int TAIL_BYTES = 8192;

    private const int STOP_GRACE_SECONDS = 5;

    /**
     * The POSIX signal numbers themselves rather than ext-pcntl's constants:
     * a PHP build without that extension, which is every official image, must
     * still be able to stop a command.
     */
    private const int SIGNAL_TERMINATE = 15;

    private const int SIGNAL_KILL = 9;

    private const string ENV_SCHEDULE = 'SCHEDULER_SCHEDULE';

    private const string ENV_TICK = 'SCHEDULER_TICK';

    private const string ENV_ATTEMPT = 'SCHEDULER_ATTEMPT';

    private const string UNLISTED_NOTE = 'The process tree could not be listed on this host, so child processes may outlive the command.';

    private bool $stopping = false;

    private bool $unlisted = false;

    /** @var list<Process> */
    private array $live = [];

    /** @var Closure(int): (list<int>|null) */
    private readonly Closure $listDescendants;

    /**
     * @param Closure(int): (list<int>|null)|null $listDescendants Answers every descendant of a pid, null for the host's own
     */
    public function __construct(Closure|null $listDescendants = null)
    {
        $this->listDescendants = $listDescendants ?? ProcessTable::descendants(...);
    }

    /**
     * @param TaskSpec $task What to execute and under which limits
     * @param Closure(): bool|null $onPoll Invoked between polls; false stops the command
     *
     * @return RunnerResult How the execution ended.
     */
    public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult
    {
        $process = Process::fromShellCommandline($task->command, $task->cwd, $this->environment($task));

        $startedAt = microtime(true);
        $lastOutputAt = $startedAt;
        $outcome = RunnerOutcome::Completed;
        $error = null;
        $this->stopping = false;
        $this->unlisted = false;
        $tail = '';

        try {
            $process->start();
            $this->live[] = $process;

            while ($process->isRunning()) {
                $tail = $this->extendTail($tail, $process, $lastOutputAt);

                if (microtime(true) - $startedAt >= $task->timeoutSeconds) {
                    $outcome = RunnerOutcome::TimedOut;
                    $error = sprintf('Timed out after %d seconds.', $task->timeoutSeconds);
                    $this->terminateTree($process, 0);

                    break;
                }

                if ($task->idleTimeoutSeconds !== null && microtime(true) - $lastOutputAt >= $task->idleTimeoutSeconds) {
                    $outcome = RunnerOutcome::TimedOut;
                    $error = sprintf('No output after %d seconds.', $task->idleTimeoutSeconds);
                    $this->terminateTree($process, 0);

                    break;
                }

                if ($this->stopRequested()) {
                    $outcome = RunnerOutcome::Interrupted;
                    $error = 'Stopped for host shutdown.';
                    $this->terminateTree($process, 0);

                    break;
                }

                if ($onPoll !== null && ($onPoll)() === false) {
                    $outcome = RunnerOutcome::Interrupted;
                    $error = 'Stopped by the engine: the run was adopted elsewhere.';
                    $this->terminateTree($process, 0);

                    break;
                }

                usleep(self::POLL_MICROSECONDS);
            }

            $tail = $this->extendTail($tail, $process, $lastOutputAt);

            try {
                $process->wait();
            } catch (Throwable $signaled) {
                unset($signaled);
            }
        } catch (Throwable $e) {
            $outcome = RunnerOutcome::Failed;
            $error = $e->getMessage();

            if ($process->isRunning()) {
                $this->terminateTree($process, self::STOP_GRACE_SECONDS);
            }
        } finally {
            $this->forget($process);
        }

        $exitCode = $process->getExitCode();

        if ($this->stopRequested()) {
            $outcome = RunnerOutcome::Interrupted;
            $error = 'Stopped for host shutdown.';
        } elseif ($outcome === RunnerOutcome::Completed && $exitCode !== 0) {
            $outcome = RunnerOutcome::Failed;
            $error = sprintf('Exited with code %s.', $exitCode === null ? 'unknown' : (string) $exitCode);
        }

        if ($this->unlisted && $error !== null) {
            $error .= ' ' . self::UNLISTED_NOTE;
        }

        return new RunnerResult(
            outcome: $outcome,
            exitCode: $exitCode,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            outputTail: $this->sanitizeTail($tail),
            error: $error,
        );
    }

    /**
     * @return void
     */
    public function stopAll(): void
    {
        $this->stopping = true;

        foreach ($this->live as $process) {
            $this->terminateTree($process, self::STOP_GRACE_SECONDS);
        }

        $this->live = [];
    }

    /**
     * Stop a command and everything it spawned. The tree is captured before
     * the first signal, because the shell's death re-parents its children;
     * after a grace window it is listed again for children forked meanwhile,
     * and the kill reaches only members still running as the processes they
     * were captured as.
     *
     * @param Process $process The command being stopped
     * @param int $grace Seconds to wait after SIGTERM before SIGKILL
     *
     * @return void
     */
    private function terminateTree(Process $process, int $grace): void
    {
        $tree = ProcessTree::under($process->getPid(), $this->listDescendants);
        $tree->signal(self::SIGNAL_TERMINATE);

        if ($grace > 0) {
            $tree->awaitExit($grace);
            $tree = $tree->regrown($this->listDescendants);
        }

        $tree->escalate(self::SIGNAL_KILL);
        $this->unlisted = $this->unlisted || !$tree->walked;
        $process->stop(0);
    }

    /**
     * The command's environment: the configured variables plus what the
     * command is told about its own fire, which the schedule always decides.
     *
     * @param TaskSpec $task The task about to run
     *
     * @return array<string, string>
     */
    private function environment(TaskSpec $task): array
    {
        if ($task->tick === null) {
            return $task->env;
        }

        return [
            ...$task->env,
            self::ENV_SCHEDULE => $task->scheduleId ?? '',
            self::ENV_TICK => $task->tick,
            self::ENV_ATTEMPT => (string) $task->attempt,
        ];
    }

    /**
     * Fold newly produced output into the rolling tail, discarding everything
     * older than the tail window: memory stays the tail's size, never the
     * command's whole output.
     *
     * @param string $tail The tail accumulated so far
     * @param Process $process The running command
     * @param float $lastOutputAt Set to now whenever bytes arrive
     *
     * @return string The tail including everything newly drained.
     */
    private function extendTail(string $tail, Process $process, float &$lastOutputAt): string
    {
        $drained = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();

        if ($drained !== '') {
            $lastOutputAt = microtime(true);
        }

        $combined = $tail . $drained;

        if (strlen($combined) <= self::TAIL_BYTES * 2) {
            return $combined;
        }

        return substr($combined, -self::TAIL_BYTES * 2);
    }

    /**
     * The wire-safe tail: cut at the byte window and scrubbed to valid UTF-8,
     * so a boundary split or non-UTF-8 command output can never make the
     * final write fail and resurrect a finished run.
     *
     * @param string $tail The accumulated tail
     *
     * @return string The last TAIL_BYTES, valid UTF-8.
     */
    private function sanitizeTail(string $tail): string
    {
        if (strlen($tail) > self::TAIL_BYTES) {
            $tail = substr($tail, -self::TAIL_BYTES);
        }

        return mb_convert_encoding($tail, 'UTF-8', 'UTF-8');
    }

    /**
     * Read in its own scope: stopAll() runs from a signal handler, which the
     * polling loop's flow analysis cannot see writing the flag.
     *
     * @return bool True once stopAll() has been called.
     */
    private function stopRequested(): bool
    {
        return $this->stopping;
    }

    /**
     * @param Process $process The finished process to stop tracking
     *
     * @return void
     */
    private function forget(Process $process): void
    {
        $this->live = array_values(array_filter(
            $this->live,
            static fn(Process $tracked): bool => $tracked !== $process,
        ));
    }
}
