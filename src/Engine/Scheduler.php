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
use PHPdot\Scheduler\Runner\TaskSpec;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class Scheduler
{
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
            $outcomes[] = $this->evaluate($definition, $at);
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
        $this->runner->stopAll();
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
        );

        $lastBeat = 0.0;
        $result = $this->runner->run($task, function () use ($run, &$lastBeat): void {
            $lastBeat = $this->renewLease($run, $lastBeat);
        });

        $state = match ($result->outcome) {
            RunnerOutcome::Completed => RunState::Succeeded,
            RunnerOutcome::Failed, RunnerOutcome::TimedOut, RunnerOutcome::Interrupted => RunState::Failed,
        };

        $recorded = $this->store->finish($run, new RunOutcome(
            $state,
            $result->exitCode,
            $result->durationMs,
            $result->outputTail,
            $result->error,
        ));

        if (!$recorded) {
            return new TickOutcome($definition->id, $run->tick, TickResult::Fenced, $result->durationMs, 'a newer attempt owns the run');
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
     * Renew the claim's lease; a store hiccup must never kill healthy work —
     * the lease TTL and fencing are the safety nets, so failure is swallowed.
     *
     * @param RunRecord $run The run whose lease is renewed
     * @param float $lastBeat When the lease was last renewed
     *
     * @return float The renewal time, or the previous beat on failure.
     */
    private function renewLease(RunRecord $run, float $lastBeat): float
    {
        $at = microtime(true);

        if ($at - $lastBeat < $this->config->heartbeatSeconds) {
            return $lastBeat;
        }

        try {
            $this->store->heartbeat($run, $this->config->leaseTtlSeconds);
        } catch (Throwable) {
            return $lastBeat;
        }

        return $at;
    }
}
