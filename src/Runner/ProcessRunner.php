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
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessRunner implements TaskRunnerInterface
{
    private const int POLL_MICROSECONDS = 250000;

    private const int TAIL_BYTES = 8192;

    private const int STOP_GRACE_SECONDS = 5;

    private bool $stopping = false;

    /** @var list<Process> */
    private array $live = [];

    /**
     * @param TaskSpec $task What to execute and under which limits
     * @param Closure|null $onPoll Invoked between polls while the command runs
     *
     * @return RunnerResult How the execution ended.
     */
    public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult
    {
        $process = Process::fromShellCommandline($task->command, $task->cwd, $task->env);
        $process->setTimeout($task->timeoutSeconds);

        if ($task->idleTimeoutSeconds !== null) {
            $process->setIdleTimeout($task->idleTimeoutSeconds);
        }

        $startedAt = microtime(true);
        $outcome = RunnerOutcome::Completed;
        $error = null;
        $this->stopping = false;

        try {
            $process->start();
            $this->live[] = $process;

            while ($process->isRunning()) {
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException $e) {
                    $idle = $e->isIdleTimeout();
                    $outcome = RunnerOutcome::TimedOut;
                    $error = sprintf(
                        '%s after %d seconds.',
                        $idle ? 'No output' : 'Timed out',
                        $idle ? ($task->idleTimeoutSeconds ?? $task->timeoutSeconds) : $task->timeoutSeconds,
                    );
                    $process->stop(0);

                    break;
                }

                if ($this->stopRequested()) {
                    $outcome = RunnerOutcome::Interrupted;
                    $error = 'Stopped for host shutdown.';
                    $process->stop(self::STOP_GRACE_SECONDS);

                    break;
                }

                if ($onPoll !== null) {
                    ($onPoll)();
                }

                usleep(self::POLL_MICROSECONDS);
            }

            $process->wait();
        } catch (Throwable $e) {
            $outcome = RunnerOutcome::Failed;
            $error = $e->getMessage();

            if ($process->isRunning()) {
                $process->stop(self::STOP_GRACE_SECONDS);
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
            outputTail: $this->tail($process),
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
            $process->stop(self::STOP_GRACE_SECONDS);
        }

        $this->live = [];
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

    /**
     * @param Process $process The finished process whose output is kept
     *
     * @return string The last 8 KiB of the command's combined output.
     */
    private function tail(Process $process): string
    {
        $combined = $process->getOutput() . $process->getErrorOutput();

        if (strlen($combined) <= self::TAIL_BYTES) {
            return $combined;
        }

        return substr($combined, -self::TAIL_BYTES);
    }
}
