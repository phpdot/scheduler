<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Process;

use PHPdot\Scheduler\Runner\ProcessRunner;
use PHPdot\Scheduler\Runner\RunnerOutcome;
use PHPdot\Scheduler\Runner\TaskSpec;
use PHPdot\Scheduler\Tests\Support\AwaitsExit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    use AwaitsExit;

    #[Test]
    public function aCompletingCommandCarriesItsOutputAndExitCode(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            'echo scheduler-ok && echo scheduler-err 1>&2',
            10,
            null,
            null,
        ));

        self::assertSame(RunnerOutcome::Completed, $result->outcome);
        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('scheduler-ok', $result->outputTail);
        self::assertStringContainsString('scheduler-err', $result->outputTail);
        self::assertNull($result->error);
    }

    #[Test]
    public function aNonZeroExitFailsTheResult(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec('exit 7', 10, null, null));

        self::assertSame(RunnerOutcome::Failed, $result->outcome);
        self::assertSame(7, $result->exitCode);
        self::assertStringContainsString('code 7', (string) $result->error);
    }

    #[Test]
    public function aWallClockTimeoutKillsTheCommand(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec('sleep 30', 1, null, null));

        self::assertSame(RunnerOutcome::TimedOut, $result->outcome);
        self::assertStringContainsString('Timed out after 1 seconds', (string) $result->error);
        self::assertLessThan(5000, $result->durationMs);
    }

    #[Test]
    public function anIdleTimeoutKillsASilentCommand(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec('sleep 30', 30, 1, null));

        self::assertSame(RunnerOutcome::TimedOut, $result->outcome);
        self::assertStringContainsString('No output after 1 seconds', (string) $result->error);
    }

    #[Test]
    public function stopAllInterruptsARunningCommand(): void
    {
        $runner = new ProcessRunner();
        $result = $runner->run(new TaskSpec('sleep 30', 30, null, null), static function () use ($runner): bool {
            $runner->stopAll();

            return false;
        });

        self::assertSame(RunnerOutcome::Interrupted, $result->outcome);
        self::assertSame('Stopped for host shutdown.', $result->error);
        self::assertLessThan(3000, $result->durationMs);
    }

    #[Test]
    public function aTimeoutKillsTheCommandsWithinTheWholeTree(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('ext-posix absent; the tree kill cannot be asserted');
        }

        $marker = sys_get_temp_dir() . '/scheduler-tree-' . uniqid();
        $result = (new ProcessRunner())->run(new TaskSpec(
            sprintf('sleep 30 & echo $! > %s; wait', escapeshellarg($marker)),
            1,
            null,
            null,
        ));

        self::assertSame(RunnerOutcome::TimedOut, $result->outcome);
        usleep(300000);

        $child = (int) trim((string) file_get_contents($marker));
        @unlink($marker);

        self::assertGreaterThan(0, $child);
        self::assertFalse(posix_kill($child, 0), 'the spawned child outlived the timeout');
    }

    #[Test]
    public function nonUtf8OutputIsScrubbedBeforeTheFinalWrite(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            "printf '\xff\xfe broken bytes' && echo ' tail-marker'",
            10,
            null,
            null,
        ));

        self::assertSame(RunnerOutcome::Completed, $result->outcome);
        self::assertStringContainsString('tail-marker', $result->outputTail);
        self::assertTrue(mb_check_encoding($result->outputTail, 'UTF-8'));
    }

    #[Test]
    public function aMultibyteCharacterSplitByTheTailWindowIsRepaired(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            "awk 'BEGIN { for (i = 0; i < 8190; i++) printf \"a\" }' && printf '\360\237\223\205' && echo end-marker",
            10,
            null,
            null,
        ));

        self::assertSame(RunnerOutcome::Completed, $result->outcome);
        self::assertStringContainsString('end-marker', $result->outputTail);
        self::assertTrue(mb_check_encoding($result->outputTail, 'UTF-8'));
    }

    #[Test]
    public function aPollAnsweringFalseStopsTheCommandForTheEngine(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            'sleep 30',
            30,
            null,
            null,
        ), static fn(): bool => false);

        self::assertSame(RunnerOutcome::Interrupted, $result->outcome);
        self::assertStringContainsString('adopted elsewhere', (string) $result->error);
        self::assertLessThan(10000, $result->durationMs);
    }

    #[Test]
    public function aChildThatIgnoresTerminationIsKilledAfterTheGrace(): void
    {
        $runner = new ProcessRunner();
        $marker = sys_get_temp_dir() . '/scheduler-stubborn-' . uniqid();
        $ready = $marker . '.ready';
        $result = $runner->run(new TaskSpec(
            sprintf('trap "" TERM; touch %1$s; sleep 60 & echo $! > %2$s; wait', escapeshellarg($ready), escapeshellarg($marker)),
            30,
            null,
            null,
        ), static function () use ($runner, $ready): bool {
            if (!file_exists($ready)) {
                return true;
            }

            $runner->stopAll();

            return false;
        });

        $child = (int) trim((string) @file_get_contents($marker));
        @unlink($marker);
        @unlink($ready);

        self::assertSame(RunnerOutcome::Interrupted, $result->outcome);
        self::assertGreaterThanOrEqual(4500, $result->durationMs);
        self::assertLessThan(9000, $result->durationMs);
        self::assertTrue($this->exitsWithin($child), 'a child that ignored SIGTERM outlived the grace window');
    }

    #[Test]
    public function aTreeTheHostCannotListIsReportedOnTheResult(): void
    {
        $runner = new ProcessRunner(static fn(int $pid): null => null);
        $marker = sys_get_temp_dir() . '/scheduler-unlisted-' . uniqid();
        $result = $runner->run(new TaskSpec(
            sprintf('sleep 60 & echo $! > %s; wait', escapeshellarg($marker)),
            1,
            null,
            null,
        ));

        $child = (int) trim((string) @file_get_contents($marker));
        @unlink($marker);
        $survived = $child > 0 && posix_kill($child, 0);
        @posix_kill($child, 9);

        self::assertSame(RunnerOutcome::TimedOut, $result->outcome);
        self::assertStringContainsString('could not be listed', (string) $result->error);
        self::assertTrue($survived, 'with no listing only the shell itself is stopped');
    }

    #[Test]
    public function aCommandIsToldWhichFireItRuns(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            'printf "%s|%s|%s" "$SCHEDULER_SCHEDULE" "$SCHEDULER_TICK" "$SCHEDULER_ATTEMPT"',
            10,
            null,
            null,
            ['SCHEDULER_TICK' => 'overridden-by-the-schedule'],
            'reports:generate',
            '2026-09-05T12:00:00+00:00',
            3,
        ));

        self::assertSame(RunnerOutcome::Completed, $result->outcome);
        self::assertSame('reports:generate|2026-09-05T12:00:00+00:00|3', $result->outputTail);
    }

    #[Test]
    public function aCommandOutsideTheEngineSeesNoFireVariables(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec('echo "[$SCHEDULER_TICK]"', 10, null, null));

        self::assertSame("[]\n", $result->outputTail);
    }

    #[Test]
    public function theOutputTailKeepsOnlyTheEnd(): void
    {
        $result = (new ProcessRunner())->run(new TaskSpec(
            'for i in $(seq 1 3000); do echo "line-$i"; done; echo final-line',
            30,
            null,
            null,
        ));

        self::assertSame(RunnerOutcome::Completed, $result->outcome);
        self::assertStringContainsString('final-line', $result->outputTail);
        self::assertStringNotContainsString('line-1' . "\n", $result->outputTail);
        self::assertLessThanOrEqual(8192, strlen($result->outputTail));
    }
}
