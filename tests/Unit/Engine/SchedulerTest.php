<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Engine;

use Closure;
use DateTimeImmutable;
use PHPdot\Scheduler\Config\SchedulerConfig;
use PHPdot\Scheduler\Contract\TaskRunnerInterface;
use PHPdot\Scheduler\Engine\Scheduler;
use PHPdot\Scheduler\Engine\TickResult;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Runner\RunnerOutcome;
use PHPdot\Scheduler\Runner\RunnerResult;
use PHPdot\Scheduler\Runner\TaskSpec;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use PHPdot\Scheduler\Tests\Support\FakeRunner;
use PHPdot\Scheduler\Tests\Support\FakeStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    #[Test]
    public function aPatternThatIsNotDueClaimsNothing(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'nightly',
            command: 'dot nightly',
            pattern: Pattern::cron('0 3 * * *'),
        ));
        $runner = new FakeRunner();
        $engine = new Scheduler($store, $runner, new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:00:00+00:00'));

        self::assertSame(TickResult::NotDue, $report->outcomes[0]->result);
        self::assertSame([], $store->runs);
        self::assertSame([], $runner->tasks);
    }

    #[Test]
    public function aWonClaimExecutesAndRecordsTheOutcome(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            timeoutSeconds: 45,
        ));
        $runner = new FakeRunner();
        $engine = new Scheduler($store, $runner, new SchedulerConfig(serverHost: 'box-1'));

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Succeeded, $report->outcomes[0]->result);
        self::assertSame('2026-09-05T12:34:00+00:00', $report->outcomes[0]->tick);
        self::assertSame('dot reports:generate', $runner->tasks[0]->command);
        self::assertSame(45, $runner->tasks[0]->timeoutSeconds);
        self::assertSame(RunState::Succeeded, $store->runs['every-minute|2026-09-05T12:34:00+00:00']->state);
    }

    #[Test]
    public function aTakenTickReportsTheOverlapSkip(): void
    {
        $store = new FakeStore();
        $definition = new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        );
        $store->saveDefinition($definition);
        $store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-2:77', 'box-2', 60, 5);
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig(serverHost: 'box-1'));

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::OverlapSkipped, $report->outcomes[0]->result);
    }

    #[Test]
    public function aFullHostReportsBusy(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->liveRunsForHost = 1;
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig(concurrencyLimit: 1));

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::HostBusy, $report->outcomes[0]->result);
    }

    #[Test]
    public function anImpossiblePatternFailsOnlyItsOwnSchedule(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'impossible',
            command: 'dot doomed',
            pattern: Pattern::cron('0 0 31 2 *'),
        ));
        $store->saveDefinition(new ScheduleDefinition(
            id: 'healthy',
            command: 'dot fine',
            pattern: Pattern::every('60s'),
        ));
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));
        $bySchedule = [];

        foreach ($report->outcomes as $outcome) {
            $bySchedule[$outcome->scheduleId] = $outcome->result;
        }

        self::assertSame(TickResult::PatternError, $bySchedule['impossible']);
        self::assertSame(TickResult::Succeeded, $bySchedule['healthy']);
    }

    #[Test]
    public function aFailedExecutionRecordsTheFailure(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'flaky',
            command: 'dot flaky',
            pattern: Pattern::every('60s'),
        ));
        $runner = new FakeRunner();
        $runner->nextReturns(new RunnerResult(RunnerOutcome::Failed, 7, 120, 'partial output', 'Exited with code 7.'));
        $engine = new Scheduler($store, $runner, new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Failed, $report->outcomes[0]->result);
        $run = $store->runs['flaky|2026-09-05T12:34:00+00:00'];
        self::assertSame(RunState::Failed, $run->state);
        self::assertSame(7, $run->exitCode);
        self::assertSame('Exited with code 7.', $run->error);
    }

    #[Test]
    public function aFencedFinishReportsThatANewerAttemptOwnsTheRun(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->refuseFinish = true;
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Fenced, $report->outcomes[0]->result);
    }

    #[Test]
    public function theEngineRenewsTheLeaseWhileTheTaskRuns(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $runner = new FakeRunner();
        $engine = new Scheduler($store, $runner, new SchedulerConfig(heartbeatSeconds: 15));

        $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(1, $store->heartbeats);
    }

    #[Test]
    public function aFailingHeartbeatNeverKillsHealthyWork(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->heartbeatsThrow = true;
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Succeeded, $report->outcomes[0]->result);
        self::assertSame(1, $store->heartbeats);
    }

    #[Test]
    public function aRefusedHeartbeatStopsTheExecutionAndReportsFenced(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->heartbeatsRefused = true;
        $runner = new FakeRunner();
        $engine = new Scheduler($store, $runner, new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Interrupted, $report->outcomes[0]->result);
        self::assertStringContainsString('Lease lost', (string) $report->outcomes[0]->detail);
        self::assertSame(RunState::Failed, $store->runs['every-minute|2026-09-05T12:34:00+00:00']->state);
    }

    #[Test]
    public function aHeartbeatOutagePastTheLeaseStopsTheExecution(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->heartbeatsThrow = true;
        $runner = new class implements TaskRunnerInterface {
            public function run(TaskSpec $task, Closure|null $onPoll = null): RunnerResult
            {
                for ($poll = 0; $poll < 5; $poll++) {
                    if ($onPoll !== null && ($onPoll)() === false) {
                        return new RunnerResult(RunnerOutcome::Interrupted, null, 0, '', 'stopped by the engine');
                    }

                    if ($poll < 4) {
                        sleep(1);
                    }
                }

                return new RunnerResult(RunnerOutcome::Completed, 0, 5, 'done', null);
            }

            public function stopAll(): void {}
        };
        $engine = new Scheduler($store, $runner, new SchedulerConfig(leaseTtlSeconds: 3, heartbeatSeconds: 1));

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Interrupted, $report->outcomes[0]->result);
        self::assertStringContainsString('Lease lost', (string) $report->outcomes[0]->detail);
    }

    #[Test]
    public function aFailedFinishWriteNeverResurrectsARun(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'every-minute',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        ));
        $store->finishThrows = true;
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig());

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame(TickResult::Fenced, $report->outcomes[0]->result);
        self::assertSame(RunState::Running, $store->runs['every-minute|2026-09-05T12:34:00+00:00']->state);
    }

    #[Test]
    public function abortStopsStartingRemainingSchedules(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'first',
            command: 'dot one',
            pattern: Pattern::every('60s'),
        ));
        $store->saveDefinition(new ScheduleDefinition(
            id: 'second',
            command: 'dot two',
            pattern: Pattern::every('60s'),
        ));
        $runner = new FakeRunner();
        $engine = new Scheduler($store, $runner, new SchedulerConfig());
        $engine->abort();

        $report = $engine->tick(new DateTimeImmutable('2026-09-05T12:34:10+00:00'));

        self::assertSame([], $runner->tasks);
        self::assertSame([], $report->outcomes);
    }

    #[Test]
    public function triggerFiresImmediatelyWithoutAPattern(): void
    {
        $store = new FakeStore();
        $store->saveDefinition(new ScheduleDefinition(
            id: 'nightly',
            command: 'dot nightly',
            pattern: Pattern::cron('0 3 * * *'),
        ));
        $engine = new Scheduler($store, new FakeRunner(), new SchedulerConfig());

        self::assertNull($engine->trigger('missing'));

        $outcome = $engine->trigger('nightly', new DateTimeImmutable('2026-09-05T12:00:00+00:00'));

        self::assertSame(TickResult::Succeeded, $outcome?->result);
        self::assertSame('2026-09-05T12:00:00+00:00', $outcome?->tick);
    }
}
