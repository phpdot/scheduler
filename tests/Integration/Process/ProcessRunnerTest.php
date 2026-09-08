<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Process;

use PHPdot\Scheduler\Runner\ProcessRunner;
use PHPdot\Scheduler\Runner\RunnerOutcome;
use PHPdot\Scheduler\Runner\TaskSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
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
        $result = $runner->run(new TaskSpec('sleep 30', 30, null, null), static function () use ($runner): void {
            $runner->stopAll();
        });

        self::assertSame(RunnerOutcome::Interrupted, $result->outcome);
        self::assertSame('Stopped for host shutdown.', $result->error);
        self::assertLessThan(10000, $result->durationMs);
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
