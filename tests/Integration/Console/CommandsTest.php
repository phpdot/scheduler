<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Console;

use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunState;
use PHPUnit\Framework\Attributes\Test;

final class CommandsTest extends CommandTestCase
{
    #[Test]
    public function installThenDefineStoresASchedule(): void
    {
        [$exit] = $this->runCommand('scheduler:install');

        self::assertSame(0, $exit);

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --every=30s --timeout=45 --description="demo schedule"',
            'reports:generate',
            escapeshellarg('echo generated'),
        ));

        self::assertSame(0, $exit);
        self::assertStringContainsString('Saved schedule "reports:generate"', $output);
        self::assertStringContainsString('30s', $output);
        self::assertStringContainsString('45s', $output);

        $definition = $this->store->definition('reports:generate');

        self::assertNotNull($definition);
        self::assertSame('echo generated', $definition->command);
        self::assertSame('30s', $definition->pattern->expression);
        self::assertSame(45, $definition->timeoutSeconds);
        self::assertSame('demo schedule', $definition->description);
        self::assertTrue($definition->enabled);
    }

    #[Test]
    public function defineUpdatesMergeWithTheStoredSchedule(): void
    {
        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'demo', escapeshellarg('echo first')));

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --timeout=10',
            'demo',
            escapeshellarg('echo second'),
        ));

        self::assertSame(0, $exit);

        $definition = $this->store->definition('demo');

        self::assertNotNull($definition);
        self::assertSame('echo second', $definition->command);
        self::assertSame('30s', $definition->pattern->expression);
        self::assertSame(10, $definition->timeoutSeconds);
        self::assertTrue($definition->enabled);
        self::assertStringContainsString('yes', $output);
    }

    #[Test]
    public function defineRejectsImpossibleInput(): void
    {
        [$exit, $output] = $this->runCommand(sprintf('scheduler:define %s %s', 'demo', escapeshellarg('echo x')));
        self::assertSame(1, $exit);
        self::assertStringContainsString('needs a timing', $output);

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --cron="* * * * *" --every=30s',
            'demo',
            escapeshellarg('echo x'),
        ));
        self::assertSame(1, $exit);
        self::assertStringContainsString('exactly one timing', $output);

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --cron="nonsense"',
            'demo',
            escapeshellarg('echo x'),
        ));
        self::assertSame(1, $exit);
        self::assertStringContainsString('Invalid schedule pattern', $output);

        [$exit] = $this->runCommand('scheduler:define demo');
        self::assertSame(1, $exit);
    }

    #[Test]
    public function listShowsStoredSchedulesAndTheirNextFire(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('No schedules stored', $output);

        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'demo', escapeshellarg('echo ok')));

        [$exit, $output] = $this->runCommand('scheduler:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('demo', $output);
        self::assertStringContainsString('30s', $output);
        self::assertStringContainsString('echo ok', $output);
    }

    #[Test]
    public function tickRunsTheDueScheduleOnceThenLosesTheClaim(): void
    {
        $this->runCommand(sprintf(
            'scheduler:define %s %s --every=1h --grace=3700',
            'demo',
            escapeshellarg('echo tick-ran'),
        ));

        [$firstExit, $firstOutput] = $this->runCommand('scheduler:tick');
        [$secondExit, $secondOutput] = $this->runCommand('scheduler:tick');

        self::assertSame(0, $firstExit);
        self::assertStringContainsString('succeeded', $firstOutput);
        self::assertSame(0, $secondExit);
        self::assertStringContainsString('claim_lost', $secondOutput);

        $runs = $this->store->runs('demo', 10);

        self::assertCount(1, $runs);
        self::assertSame(RunState::Succeeded, $runs[0]->state);
        self::assertSame(0, $runs[0]->exitCode);
    }

    #[Test]
    public function runsShowsHistory(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:runs');

        self::assertSame(0, $exit);
        self::assertStringContainsString('No runs recorded', $output);

        $this->runCommand(sprintf(
            'scheduler:define %s %s --every=1h --grace=3700',
            'demo',
            escapeshellarg('echo history'),
        ));
        $this->runCommand('scheduler:tick');

        [$exit, $output] = $this->runCommand('scheduler:runs --schedule=demo');

        self::assertSame(0, $exit);
        self::assertStringContainsString('succeeded', $output);
        self::assertStringContainsString('demo', $output);
    }

    #[Test]
    public function triggerFiresNowRegardlessOfPattern(): void
    {
        $this->runCommand(sprintf(
            'scheduler:define %s %s --cron="0 3 * * *"',
            'nightly',
            escapeshellarg('echo triggered'),
        ));

        [$exit, $output] = $this->runCommand('scheduler:trigger nightly');

        self::assertSame(0, $exit);
        self::assertStringContainsString('succeeded', $output);

        [$exit, $output] = $this->runCommand('scheduler:trigger missing');

        self::assertSame(1, $exit);
        self::assertStringContainsString('No schedule "missing"', $output);
    }

    #[Test]
    public function pruneClosesDiedFiresAndClearsOldHistory(): void
    {
        $this->runCommand(sprintf(
            'scheduler:define %s %s --every=1h --grace=3700',
            'demo',
            escapeshellarg('echo done'),
        ));

        $definition = $this->store->definition('demo');

        self::assertNotNull($definition);

        $died = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'test-host:999', 'test-host', 60, 5);

        self::assertNotNull($died);

        $past = gmdate('Y-m-d H:i:s', time() - 400) . '.000000';
        $this->connection->affectingStatement(
            'UPDATE scheduler_runs SET lease_expires_at = ? WHERE id = ?',
            [$past, $died->id],
        );

        [$exit, $output] = $this->runCommand('scheduler:prune --stale-grace=300 --days=0');

        self::assertSame(0, $exit);
        self::assertStringContainsString('1 died fire closed as expired', $output);

        $finished = $this->store->claim($definition, '2026-09-05T13:00:00+00:00', 'test-host:999', 'test-host', 60, 5);

        self::assertNotNull($finished);
        self::assertTrue($this->store->finish($finished, new RunOutcome(RunState::Succeeded, 0, 5, 'done', null)));
        $this->connection->affectingStatement(
            'UPDATE scheduler_runs SET started_at = ?, finished_at = ? WHERE schedule_id = ?',
            [$this->daysAgo(2), $this->daysAgo(2), $definition->id],
        );

        [$exit, $output] = $this->runCommand('scheduler:prune --stale-grace=300 --days=0');

        self::assertSame(0, $exit);
        self::assertStringContainsString('2 terminal runs deleted', $output);
        self::assertCount(0, $this->store->runs('demo', 10));
    }

    #[Test]
    public function syncBuildsTheCatalogOfSchedulableCommands(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:sync --bin=dot');

        self::assertSame(0, $exit);
        self::assertStringContainsString('added: 2', $output);
        self::assertCount(0, $this->store->allDefinitions());

        $entries = [];

        foreach ($this->store->catalogEntries() as $entry) {
            $entries[$entry->name] = $entry;
        }

        self::assertSame('dot demo:seeded', $entries['demo:seeded']->command);
        self::assertSame('Schedulable demo command', $entries['demo:seeded']->description);
        self::assertSame('dot demo:other', $entries['custom-catalog-id']->command);
        self::assertSame('Overridden note', $entries['custom-catalog-id']->description);

        [$exit] = $this->runCommand('scheduler:sync --bin=dot');

        self::assertSame(0, $exit);
        self::assertCount(2, $this->store->catalogEntries());
    }

    #[Test]
    public function defineSchedulesACatalogEntryWithOnlyATiming(): void
    {
        $this->runCommand('scheduler:sync --bin=dot');

        [$exit, $output] = $this->runCommand('scheduler:define demo:seeded --every=30s');

        self::assertSame(0, $exit);

        $definition = $this->store->definition('demo:seeded');

        self::assertNotNull($definition);
        self::assertSame('dot demo:seeded', $definition->command);
        self::assertSame('30s', $definition->pattern->expression);
        self::assertTrue($definition->enabled);

        [$exit] = $this->runCommand('scheduler:define demo:seeded --every=45s');

        self::assertSame(0, $exit);
        self::assertSame('45s', $this->store->definition('demo:seeded')?->pattern->expression);
    }

    #[Test]
    public function defineWithoutCommandOrCatalogEntryRefuses(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:define ghost --every=30s');

        self::assertSame(1, $exit);
        self::assertStringContainsString('scheduler:sync', $output);
        self::assertNull($this->store->definition('ghost'));
    }

    #[Test]
    public function listShowsUnscheduledCatalogEntriesAsAvailable(): void
    {
        $this->runCommand('scheduler:sync --bin=dot');

        [$exit, $output] = $this->runCommand('scheduler:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('available', $output);
        self::assertStringContainsString('dot demo:seeded', $output);

        $this->runCommand('scheduler:define demo:seeded --every=30s');

        [$exit, $output] = $this->runCommand('scheduler:list');

        self::assertSame(0, $exit);
        self::assertStringContainsString('30s', $output);
        self::assertStringContainsString('custom-catalog-id', $output);
    }

    #[Test]
    public function presetsExpandIntoStoredPatterns(): void
    {
        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --preset=every-minute',
            'preset:minute',
            escapeshellarg('echo ok'),
        ));

        self::assertSame(0, $exit);
        self::assertSame('60s', $this->store->definition('preset:minute')?->pattern->expression);

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --preset=daily-midnight',
            'preset:nightly',
            escapeshellarg('echo ok'),
        ));

        self::assertSame(0, $exit);

        $expression = $this->store->definition('preset:nightly')?->pattern->expression ?? '';

        self::assertMatchesRegularExpression('/^\d{1,2} 0 \* \* \*$/', $expression);

        [$exit, $output] = $this->runCommand(sprintf('scheduler:define %s %s --preset=nonsense', 'preset:bad', escapeshellarg('echo x')));

        self::assertSame(1, $exit);
        self::assertStringContainsString('Unknown preset "nonsense"', $output);
        self::assertStringContainsString('daily-midnight', $output);

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --preset=hourly --every=30s',
            'preset:both',
            escapeshellarg('echo x'),
        ));

        self::assertSame(1, $exit);
        self::assertStringContainsString('exactly one timing', $output);
    }

    #[Test]
    public function defineWarnsWhenAnotherScheduleFiresTheSameInstant(): void
    {
        $this->runCommand(sprintf('scheduler:define %s %s --cron="0 18 * * *"', 'first:report', escapeshellarg('echo a')));

        [$exit, $output] = $this->runCommand(sprintf(
            'scheduler:define %s %s --cron="0 18 * * *"',
            'second:report',
            escapeshellarg('echo b'),
        ));

        self::assertSame(0, $exit);
        self::assertStringContainsString('first:report', $output);
        self::assertStringContainsString('also fires at', $output);

        $this->runCommand(sprintf('scheduler:define %s %s --cron="0 5 * * *"', 'third:report', escapeshellarg('echo c')));

        self::assertStringNotContainsString('also fires', $this->runCommand(sprintf(
            'scheduler:define %s %s --cron="*/7 * * * *"',
            'fourth:report',
            escapeshellarg('echo d'),
        ))[1]);
    }

    #[Test]
    public function monitorPassesAnEmptyStoreAndAScheduleThatOwesNothingYet(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:monitor');

        self::assertSame(0, $exit, 'an empty store is healthy');
        self::assertStringNotContainsString('MISSED', $output);

        $this->runCommand(sprintf('scheduler:define %s %s --cron="0 3 * * *"', 'fresh:report', escapeshellarg('echo ok')));

        [$exit, $output] = $this->runCommand('scheduler:monitor');

        self::assertSame(0, $exit, 'a schedule defined before its first fire owes nothing yet');
        self::assertStringContainsString('fresh:report', $output);
        self::assertStringContainsString('pending', $output);
        self::assertStringContainsString('never', $output);
        self::assertStringNotContainsString('MISSED', $output);
    }

    #[Test]
    public function monitorPagesAScheduleThatMissedAFireItOwed(): void
    {
        $this->establish('ghost:report', 'echo never', '--cron="0 4 * * *"');

        [$exit, $output] = $this->runCommand('scheduler:monitor');

        self::assertSame(1, $exit);
        self::assertStringContainsString('ghost:report', $output);
        self::assertStringContainsString('MISSED', $output);
    }

    #[Test]
    public function monitorPassesAScheduleWhoseLatestFireRan(): void
    {
        $this->establish('healthy:report', 'echo ok', '--cron="0 3 * * *"');
        $this->runCommand('scheduler:trigger healthy:report');

        [$exit, $output] = $this->runCommand('scheduler:monitor');

        self::assertSame(0, $exit);
        self::assertStringContainsString('healthy:report', $output);
        self::assertMatchesRegularExpression('/healthy:report[^\n]*\| ok\s+\|/s', $output);
    }

    #[Test]
    public function monitorAlarmsOnAFailureEvenWhenALaterFireSucceeded(): void
    {
        $marker = sys_get_temp_dir() . '/scheduler-flaky-' . uniqid();

        $this->establish('flaky:report', sprintf('if [ -e %1$s ]; then echo ok; else touch %1$s; exit 7; fi', escapeshellarg($marker)), '--every=1m');

        try {
            [$failedExit] = $this->runCommand('scheduler:trigger flaky:report');
            sleep(1);
            [$recoveredExit] = $this->runCommand('scheduler:trigger flaky:report');

            self::assertSame(1, $failedExit);
            self::assertSame(0, $recoveredExit);

            [$exit, $output] = $this->runCommand('scheduler:monitor');

            self::assertSame(1, $exit, 'a failure does not stop counting because the next fire recovered');
            self::assertStringContainsString('failed/expired in window: 1', $output);
            self::assertSame(RunState::Succeeded, $this->store->latestRun('flaky:report')?->state);

            [$exit] = $this->runCommand('scheduler:monitor --failed-within=0');

            self::assertSame(0, $exit, 'the failure half is switched off');
        } finally {
            @unlink($marker);
        }
    }

    #[Test]
    public function monitorAlarmsOnAFailedRun(): void
    {
        $this->establish('broken:report', 'exit 7', '--cron="0 5 * * *"');
        $this->runCommand('scheduler:trigger broken:report');

        [$exit, $output] = $this->runCommand('scheduler:monitor');

        self::assertSame(1, $exit);
        self::assertStringContainsString('failed/expired in window: 1', $output);
    }

    #[Test]
    public function triggerExitsNonZeroWhenTheRunFailedOrWasRefused(): void
    {
        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'flaky', escapeshellarg('exit 7')));

        [$exit, $output] = $this->runCommand('scheduler:trigger flaky');

        self::assertSame(1, $exit, 'a run that failed is not a successful trigger');
        self::assertStringContainsString('failed', $output);

        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'busy', escapeshellarg('sleep 20')));
        $definition = $this->store->definition('busy');

        self::assertNotNull($definition);
        self::assertNotNull($this->store->claim($definition, '2026-01-01T00:00:00+00:00', 'someone-else:1:aaaa', 'other-host', 600, 5));

        [$exit, $output] = $this->runCommand('scheduler:trigger busy');

        self::assertSame(1, $exit, 'a fire that was refused never ran');
        self::assertStringContainsString('overlap_skipped', $output);

        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'good', escapeshellarg('echo ok')));

        [$exit, $output] = $this->runCommand('scheduler:trigger good');

        self::assertSame(0, $exit);
        self::assertStringContainsString('succeeded', $output);
    }

    #[Test]
    public function defineReportsBadInputAsErrorsNotExceptions(): void
    {
        $cases = [
            ['scheduler:define bad "echo x" --cron="nonsense"', 'Invalid schedule pattern "nonsense"'],
            ['scheduler:define bad "echo x" --preset="nope"', 'Unknown preset "nope"'],
            ['scheduler:define bad "echo x" --cron="* * * * *" --overlap="sometimes"', 'Invalid schedule definition field "overlap"'],
            ['scheduler:define bad "echo x" --cron="* * * * *" --timezone="Mars/Olympus"', 'Mars/Olympus'],
            ['scheduler:define bad "echo x" --cron="* * * * *" --timeout=abc', 'Option "--timeout" needs a whole number, not "abc".'],
            ['scheduler:define bad "echo x" --cron="* * * * *" --grace=90s', 'Option "--grace" needs a whole number, not "90s".'],
        ];

        foreach ($cases as [$command, $expected]) {
            [$exit, $output] = $this->runCommand($command);

            self::assertSame(1, $exit, $command);
            self::assertStringContainsString($expected, $output, $command);
            self::assertStringNotContainsString('.php line', $output, $command);
        }

        self::assertNull($this->store->definition('bad'));
    }

    #[Test]
    public function numericOptionsRefuseATypoInsteadOfFallingBackToADefault(): void
    {
        foreach ([
            'scheduler:prune --days=abc',
            'scheduler:prune --stale-grace=1.5',
            'scheduler:runs --limit=many',
            'scheduler:monitor --failed-within=later',
            'scheduler:monitor --missed-multiplier=2x',
        ] as $command) {
            [$exit, $output] = $this->runCommand($command);

            self::assertSame(1, $exit, $command);
            self::assertStringContainsString('needs a whole number', $output, $command);
        }

        [$exit] = $this->runCommand('scheduler:prune --days=7 --stale-grace=60');

        self::assertSame(0, $exit);
    }

    #[Test]
    public function pruneReportsTheRetentionItApplies(): void
    {
        [$exit, $output] = $this->runCommand('scheduler:prune --days=0');

        self::assertSame(0, $exit);
        self::assertStringContainsString('1-day retention', $output);
        self::assertStringNotContainsString('0-day', $output);
    }

    #[Test]
    public function pauseResumeAndRemoveManageTheLifecycle(): void
    {
        $this->runCommand(sprintf('scheduler:define %s %s --every=30s', 'demo', escapeshellarg('echo x')));

        [$exit, $output] = $this->runCommand('scheduler:pause demo');

        self::assertSame(0, $exit);
        self::assertStringContainsString('paused', $output);
        $paused = $this->store->definition('demo');

        self::assertNotNull($paused);
        self::assertFalse($paused->enabled);

        [$exit, $output] = $this->runCommand('scheduler:resume demo');

        self::assertSame(0, $exit);
        self::assertStringContainsString('resumed', $output);
        $resumed = $this->store->definition('demo');

        self::assertNotNull($resumed);
        self::assertTrue($resumed->enabled);

        [$exit, $output] = $this->runCommand('scheduler:remove demo --force');

        self::assertSame(0, $exit);
        self::assertStringContainsString('Deleted schedule "demo"', $output);
        self::assertNull($this->store->definition('demo'));

        [$exit] = $this->runCommand('scheduler:resume demo');

        self::assertSame(1, $exit);
    }

    /**
     * Defines a schedule and backdates its stamp, so the monitor judges it as
     * a schedule that has been expected to fire for a day. A definition
     * stamp is whole seconds; the fraction belongs to run stamps.
     *
     * @param string $id The schedule id
     * @param string $command The command it runs
     * @param string $timing The timing options passed to scheduler:define
     *
     * @return void
     */
    private function establish(string $id, string $command, string $timing): void
    {
        [$exit] = $this->runCommand(sprintf('scheduler:define %s %s %s', $id, escapeshellarg($command), $timing));

        self::assertSame(0, $exit);
        $this->connection->affectingStatement('UPDATE scheduler_schedules SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 86400), $id]);
    }

    /**
     * @return string A store-format stamp a day count in the past.
     */
    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * 86400) . '.000000';
    }
}
