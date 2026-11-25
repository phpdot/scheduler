<?php

declare(strict_types=1);

/**
 * The tick engine: one pass decides what is due, claims each fire in the
 * store, executes what it wins, and records how it ended.
 *
 * Identical servers run the same tick; the store's atomic claim is the only
 * thing that decides who executes, so nothing about deployment has to.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Scheduler\Clock\SystemClock;
use PHPdot\Scheduler\Config\SchedulerConfig;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Contract\TaskRunnerInterface;
use PHPdot\Scheduler\Exception\SchedulerException;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Runner\ProcessRunner;
use PHPdot\Scheduler\Runner\RunnerOutcome;
use PHPdot\Scheduler\Runner\RunnerResult;
use PHPdot\Scheduler\Runner\TaskSpec;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use Psr\Clock\ClockInterface;
use Throwable;

final class Scheduler
{
    private bool $aborted = false;

    /**
     * @param SchedulerStoreInterface $store Where definitions, claims, and history live
     * @param TaskRunnerInterface $runner How claimed tasks execute
     * @param SchedulerConfig $config This server's identity and lease arithmetic
     * @param ClockInterface $clock The clock ticks evaluate against
     */
    public function __construct(
        private SchedulerStoreInterface $store,
        private TaskRunnerInterface $runner = new ProcessRunner(),
        private SchedulerConfig $config = new SchedulerConfig(),
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param DateTimeImmutable|null $now The instant to evaluate against, null for now
     *
     * @return TickReport What every enabled schedule did this pass.
     */
    public function tick(null|DateTimeImmutable $now = null): TickReport
    {
        $at = $now ?? $this->clock->now();
        $outcomes = [];

        foreach ($this->store->enabledDefinitions() as $definition) {
            if ($this->aborted) {
                break;
            }

            $outcomes[] = $this->evaluateOrReport($definition, $at);
        }

        return new TickReport($at, $outcomes);
    }

    /**
     * Fire one schedule now, bypassing its pattern but nothing else: the claim
     * still refuses to start beside a live run or over the host cap.
     *
     * @param string $scheduleId The schedule to fire
     * @param DateTimeImmutable|null $now The instant stamped as the tick, null for now
     *
     * @return TickOutcome|null What happened, null when the schedule is absent.
     */
    public function trigger(string $scheduleId, null|DateTimeImmutable $now = null): TickOutcome|null
    {
        $definition = $this->store->definition($scheduleId);

        if ($definition === null) {
            return null;
        }

        $at = ($now ?? $this->clock->now())->setTimezone(new DateTimeZone('UTC'));

        return $this->attempt($definition, $at->format(Pattern::TICK_FORMAT));
    }

    /**
     * @return void
     */
    public function abort(): void
    {
        $this->aborted = true;
        $this->runner->stopAll();
    }

    /**
     * Evaluate one schedule with its own failures kept to it: a store that
     * throws for this schedule costs this schedule the pass, never the ones
     * ordered after it.
     *
     * @param ScheduleDefinition $definition The schedule being evaluated
     * @param DateTimeImmutable $at The instant to evaluate against
     *
     * @return TickOutcome The schedule's fate this pass.
     */
    private function evaluateOrReport(ScheduleDefinition $definition, DateTimeImmutable $at): TickOutcome
    {
        try {
            return $this->evaluate($definition, $at);
        } catch (Throwable $failure) {
            error_log(sprintf('[scheduler] schedule %s skipped this pass, its store call failed: %s', $definition->id, $failure->getMessage()));

            return new TickOutcome($definition->id, null, TickResult::StoreError, null, $failure->getMessage());
        }
    }

    /**
     * @param ScheduleDefinition $definition The schedule being evaluated
     * @param DateTimeImmutable $at The instant to evaluate against
     *
     * @return TickOutcome The schedule's fate this pass.
     */
    private function evaluate(ScheduleDefinition $definition, DateTimeImmutable $at): TickOutcome
    {
        try {
            $tick = $definition->pattern->dueTick($at, $definition->timezone, $definition->graceSeconds);
        } catch (SchedulerException $e) {
            return new TickOutcome($definition->id, null, TickResult::PatternError, null, $e->getMessage());
        }

        if ($tick === null) {
            return new TickOutcome($definition->id, null, TickResult::NotDue, null, null);
        }

        return $this->attempt($definition, $tick);
    }

    /**
     * @param ScheduleDefinition $definition The schedule claiming its fire
     * @param string $tick The tick identifier being claimed
     *
     * @return TickOutcome The claim's fate, or the execution's when it won.
     */
    private function attempt(ScheduleDefinition $definition, string $tick): TickOutcome
    {
        $run = $this->store->claim(
            $definition,
            $tick,
            $this->config->resolveServerId(),
            $this->config->resolveHost(),
            $this->config->leaseTtlSeconds,
            $this->config->concurrencyLimit,
        );

        if ($run === null) {
            if ($this->store->liveRunCountForHost($this->config->resolveHost()) >= $this->config->concurrencyLimit) {
                return new TickOutcome($definition->id, $tick, TickResult::HostBusy, null, null);
            }

            $result = $this->store->hasLiveRun($definition->id)
                ? TickResult::OverlapSkipped
                : TickResult::ClaimLost;

            return new TickOutcome($definition->id, $tick, $result, null, null);
        }

        return $this->execute($definition, $run);
    }

    /**
     * @param ScheduleDefinition $definition The schedule being executed
     * @param RunRecord $run The claim this server won
     *
     * @return TickOutcome How the execution ended.
     */
    private function execute(ScheduleDefinition $definition, RunRecord $run): TickOutcome
    {
        $task = new TaskSpec(
            $definition->command,
            $definition->timeoutSeconds,
            $definition->idleTimeoutSeconds,
            $this->config->commandCwd,
            $this->config->env,
            $definition->id,
            $run->tick,
            $run->attempt,
        );

        $lastBeat = microtime(true) - $this->config->heartbeatSeconds;
        $leaseLost = false;
        $result = $this->runner->run($task, function () use ($run, &$lastBeat, &$leaseLost): bool {
            return $this->renewLease($run, $lastBeat, $leaseLost);
        });

        if ($leaseLost) {
            $result = $this->asAbandoned($result, $lastBeat);
        }

        $state = match ($result->outcome) {
            RunnerOutcome::Completed => RunState::Succeeded,
            RunnerOutcome::Failed, RunnerOutcome::TimedOut, RunnerOutcome::Interrupted => RunState::Failed,
        };

        $unrecorded = $this->finishClaimed($run, new RunOutcome(
            $state,
            $result->exitCode,
            $result->durationMs,
            $result->outputTail,
            $result->error,
        ));

        if ($unrecorded !== null) {
            return new TickOutcome($definition->id, $run->tick, TickResult::Fenced, $result->durationMs, $unrecorded);
        }

        $resultCase = match ($result->outcome) {
            RunnerOutcome::Completed => TickResult::Succeeded,
            RunnerOutcome::Failed => TickResult::Failed,
            RunnerOutcome::TimedOut => TickResult::TimedOut,
            RunnerOutcome::Interrupted => TickResult::Interrupted,
        };

        return new TickOutcome($definition->id, $run->tick, $resultCase, $result->durationMs, $result->error);
    }

    /**
     * Renew the claim's lease, saying whether the run is still ours. A store
     * hiccup is tolerated until it outlives the lease; a refused fence means
     * the run was adopted elsewhere and this execution must stop — otherwise
     * two servers run the same job side by side.
     *
     * @param RunRecord $run The run whose lease is renewed
     * @param float $lastBeat When the lease was last renewed successfully
     * @param bool $leaseLost Set when the claim is known lost
     *
     * @return bool False when the execution must stop.
     */
    private function renewLease(RunRecord $run, float &$lastBeat, bool &$leaseLost): bool
    {
        $at = microtime(true);

        if ($at - $lastBeat < $this->config->heartbeatSeconds) {
            return true;
        }

        try {
            $renewed = $this->store->heartbeat($run, $this->config->leaseTtlSeconds);
        } catch (Throwable) {
            if ($this->leaseOutlived($at, $lastBeat)) {
                $leaseLost = true;

                return false;
            }

            return true;
        }

        if (!$renewed) {
            $leaseLost = true;

            return false;
        }

        $lastBeat = $at;

        return true;
    }

    /**
     * @param float $at Now, wall clock
     * @param float $lastBeat When the lease was last renewed successfully
     *
     * @return bool True when no renewal has succeeded within the lease TTL.
     */
    private function leaseOutlived(float $at, float $lastBeat): bool
    {
        return $at - $lastBeat > $this->config->leaseTtlSeconds;
    }

    /**
     * @param RunnerResult $result The stopped execution's result
     * @param float $lastBeat When the lease was last renewed successfully
     *
     * @return RunnerResult The result retold as an abandoned run.
     */
    private function asAbandoned(RunnerResult $result, float $lastBeat): RunnerResult
    {
        $age = (int) round(microtime(true) - $lastBeat);

        return new RunnerResult(
            outcome: RunnerOutcome::Interrupted,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            outputTail: $result->outputTail,
            error: sprintf('Lease lost for %d seconds: the run was adopted elsewhere or the store was unreachable past the lease.', $age),
        );
    }

    /**
     * Record the outcome, guarding the final write: a failed write must never
     * leave a finished run claimable again. The row is re-read after a failed
     * write; a newer attempt already owning it is fenced, anything else is
     * logged for a human, and the caller hears why its outcome is not the
     * record — the store's claim record, not the execution, decides adoption.
     *
     * @param RunRecord $run The claim this server held
     * @param RunOutcome $outcome What the execution did
     *
     * @return string|null Null once recorded, else why this server's outcome is not the record.
     */
    private function finishClaimed(RunRecord $run, RunOutcome $outcome): string|null
    {
        try {
            return $this->store->finish($run, $outcome) ? null : 'a newer attempt owns the run';
        } catch (Throwable $failure) {
            error_log(sprintf('[scheduler] run %d finish write failed: %s', $run->id, $failure->getMessage()));
        }

        try {
            $current = $this->store->runByTick($run->scheduleId, $run->tick);

            if ($current !== null && $current->attempt > $run->attempt) {
                return 'a newer attempt owns the run';
            }
        } catch (Throwable) {
        }

        error_log(sprintf(
            '[scheduler] run %d (schedule %s, tick %s) finished but its outcome could not be recorded; the row may be adopted and re-run.',
            $run->id,
            $run->scheduleId,
            $run->tick,
        ));

        return 'the outcome could not be recorded; the run will be adopted and run again';
    }
}
