<?php

declare(strict_types=1);

/**
 * Executes claimed tasks through symfony/process under the safety contract:
 * a hard timeout on every command, escalation from SIGTERM to SIGKILL, and a
 * stop-everything path for host shutdown.
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

    private bool $stopping = false;

    /** @var list<Process> */
    private array $live = [];

    /**
     * @param TaskSpec $task What to execute and under which limits
     * @param Closure(): bool|null $onPoll Invoked between polls; false stops the command
     *
     * @return RunnerResult How the execution ended.
     */
    public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult
    {
        $process = Process::fromShellCommandline($task->command, $task->cwd, $task->env);

        $startedAt = microtime(true);
        $lastOutputAt = $startedAt;
        $outcome = RunnerOutcome::Completed;
        $error = null;
        $this->stopping = false;
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
     * Stop a command and everything it spawned: the shell's own children are
     * orphans to symfony's single-process stop, so the tree is walked with
     * pgrep and signalled parent-first, escalating to SIGKILL after the
     * grace window. Children a pgrep-less platform hides survive — run under
     * a supervisor when that gap cannot be tolerated.
     *
     * @param Process $process The command being stopped
     * @param int $grace Seconds between SIGTERM and SIGKILL
     *
     * @return void
     */
    private function terminateTree(Process $process, int $grace): void
    {
        $tree = $this->treePids($process->getPid());
        $this->signalAll($tree, SIGTERM);

        if ($grace > 0) {
            usleep($grace * 1000000);
        }

        $this->signalAll($tree, SIGKILL);
        $process->stop(0);
    }

    /**
     * The command's whole tree, parent first: collected before any signal is
     * delivered, because the shell's death re-parents its children and hides
     * them from pgrep.
     *
     * @param int|null $pid The command's own pid
     *
     * @return list<int> The tree's pids, the command itself first.
     */
    private function treePids(int|null $pid): array
    {
        if ($pid === null || !function_exists('posix_kill')) {
            return [];
        }

        return [$pid, ...$this->descendantPids($pid)];
    }

    /**
     * @param list<int> $pids The tree members being signalled
     * @param int $signal The signal every member receives
     *
     * @return void
     */
    private function signalAll(array $pids, int $signal): void
    {
        foreach ($pids as $pid) {
            if ($pid > 0) {
                @posix_kill($pid, $signal);
            }
        }
    }

    /**
     * @param int $pid The process whose descendants are collected
     *
     * @return list<int> Depth-first, newest descendant last.
     */
    private function descendantPids(int $pid): array
    {
        $lister = new Process(['pgrep', '-P', (string) $pid]);
        $lister->start();
        $lister->wait();

        $children = [];

        $listed = preg_split('/\s+/', trim($lister->getOutput()));
        $chunks = $listed === false ? [] : $listed;

        foreach ($chunks as $chunk) {
            if (ctype_digit($chunk)) {
                $children[] = (int) $chunk;
            }
        }

        $pids = [];

        foreach ($children as $child) {
            $pids[] = $child;
            $pids = [...$pids, ...$this->descendantPids($child)];
        }

        return $pids;
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
