<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration;

use DateTimeZone;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Exception\InvalidConfigurationException;
use PHPdot\Scheduler\Exception\SchemaMismatchException;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use PHPdot\Scheduler\Store\Columns;
use PHPdot\Scheduler\Store\RelationalStore;
use PHPdot\Scheduler\Store\SchedulerSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The claim protocol exercised identically against every relational dialect:
 * one claim wins, Forbid refuses overlaps, leases expire and are adopted,
 * fences stop zombies, and the store's own clock decides everything.
 */
abstract class RelationalStoreTestCase extends TestCase
{
    /** @var list<string> The run indexes stores written before them lack. */
    private const array LATER_INDEXES = [
        'scheduler_runs_schedule_state_started_idx',
        'scheduler_runs_state_lease_idx',
        'scheduler_runs_started_idx',
    ];

    protected DatabaseConnection $connection;

    protected RelationalStore $store;

    protected SchedulerSchema $schema;

    protected function setUp(): void
    {
        $this->connection = $this->createConnection();

        try {
            $this->connection->select('SELECT 1');
        } catch (Throwable $e) {
            $this->skipOrFail(static::class . ' unavailable: ' . $e->getMessage());
        }

        $this->schema = new SchedulerSchema($this->connection);
        $this->schema->drop();
        $this->schema->install();

        $this->store = new RelationalStore($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    abstract protected function createConnection(): DatabaseConnection;

    abstract protected function skipOrFail(string $reason): never;

    #[Test]
    public function theStoreRefusesToRunWithoutItsSchema(): void
    {
        $this->schema->drop();

        $this->expectException(SchemaMismatchException::class);

        $this->store->allDefinitions();
    }

    #[Test]
    public function definitionsRoundTripAndPauseHidesThemFromTheEngine(): void
    {
        $definition = new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate --date=yesterday',
            pattern: Pattern::every('60s'),
            timezone: new DateTimeZone('Europe/Berlin'),
            timeoutSeconds: 450,
            idleTimeoutSeconds: 60,
            overlap: OverlapPolicy::Allow,
            graceSeconds: 120,
            description: 'Nightly reports',
        );

        $this->store->saveDefinition($definition);
        $loaded = $this->store->definition('reports:generate');

        self::assertNotNull($loaded);
        self::assertSame('dot reports:generate --date=yesterday', $loaded->command);
        self::assertSame('60s', $loaded->pattern->expression);
        self::assertSame('Europe/Berlin', $loaded->timezone->getName());
        self::assertSame(450, $loaded->timeoutSeconds);
        self::assertSame(60, $loaded->idleTimeoutSeconds);
        self::assertSame(OverlapPolicy::Allow, $loaded->overlap);
        self::assertSame(120, $loaded->graceSeconds);
        self::assertSame('Nightly reports', $loaded->description);
        self::assertTrue($loaded->enabled);
        self::assertCount(1, $this->store->enabledDefinitions());

        $this->store->saveDefinition($loaded->withEnabled(false));

        self::assertCount(0, $this->store->enabledDefinitions());
        self::assertCount(1, $this->store->allDefinitions());

        self::assertTrue($this->store->deleteDefinition('reports:generate'));
        self::assertFalse($this->store->deleteDefinition('reports:generate'));
    }

    #[Test]
    public function exactlyOneServerWinsATick(): void
    {
        $definition = $this->storedDefinition();

        $won = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);
        $lost = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($won);
        self::assertSame('box-1:100', $won->claimedBy);
        self::assertSame(1, $won->attempt);
        self::assertSame(RunState::Running, $won->state);
        self::assertNull($lost);
    }

    #[Test]
    public function forbidRefusesTheNextTickWhileARunIsLive(): void
    {
        $definition = $this->storedDefinition();

        $first = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);
        $second = $this->store->claim($definition, '2026-09-05T12:35:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertTrue($this->store->hasLiveRun('every-minute'));
    }

    #[Test]
    public function allowStartsTheNextTickBesideALiveRun(): void
    {
        $definition = $this->storedDefinition(overlap: OverlapPolicy::Allow);

        $first = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);
        $second = $this->store->claim($definition, '2026-09-05T12:35:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($first);
        self::assertNotNull($second);
    }

    #[Test]
    public function aFullHostCapRefusesNewClaims(): void
    {
        $first = $this->storedDefinition(id: 'first');
        $second = $this->storedDefinition(id: 'second');

        $live = $this->store->claim($first, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 1);
        $refused = $this->store->claim($second, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 1);

        self::assertNotNull($live);
        self::assertNull($refused);
        self::assertSame(1, $this->store->liveRunCountForHost('box-1'));
        self::assertSame(0, $this->store->liveRunCountForHost('box-2'));
    }

    #[Test]
    public function heartbeatsRenewTheLeaseAndFinishRecordsTheOutcome(): void
    {
        $definition = $this->storedDefinition();
        $run = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($run);
        self::assertTrue($this->store->heartbeat($run, 120));

        $renewed = $this->store->run($run->id);
        self::assertNotNull($renewed);
        self::assertNotSame($run->leaseExpiresAt, $renewed->leaseExpiresAt);
        self::assertNotNull($renewed->heartbeatAt);

        self::assertTrue($this->store->finish($run, new RunOutcome(
            RunState::Succeeded,
            0,
            4321,
            'all done',
            null,
        )));

        $finished = $this->store->run($run->id);
        self::assertNotNull($finished);
        self::assertSame(RunState::Succeeded, $finished->state);
        self::assertSame(0, $finished->exitCode);
        self::assertSame(4321, $finished->durationMs);
        self::assertSame('all done', $finished->outputTail);
        self::assertNotNull($finished->finishedAt);
        self::assertFalse($this->store->hasLiveRun('every-minute'));

        self::assertFalse($this->store->heartbeat($run, 60));
    }

    #[Test]
    public function anExpiredLeaseIsAdoptedAndTheZombieIsFenced(): void
    {
        $definition = $this->storedDefinition();
        $zombie = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($zombie);
        $this->connection->affectingStatement(
            "UPDATE scheduler_runs SET lease_expires_at = '2000-01-01 00:00:00.000000' WHERE id = ?",
            [$zombie->id],
        );

        $adopted = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($adopted);
        self::assertSame(2, $adopted->attempt);
        self::assertSame('box-2:200', $adopted->claimedBy);

        self::assertFalse($this->store->finish($zombie, new RunOutcome(RunState::Succeeded, 0, 10, null, null)));
        self::assertFalse($this->store->heartbeat($zombie, 60));

        self::assertTrue($this->store->finish($adopted, new RunOutcome(RunState::Failed, 9, 10, null, 'adopted run failed')));
        $finished = $this->store->run($adopted->id);
        self::assertNotNull($finished);
        self::assertSame(RunState::Failed, $finished->state);
    }

    #[Test]
    public function aStaleRunPastTheGraceWindowIsAdoptedWithItsEvidenceKept(): void
    {
        $definition = $this->storedDefinition();
        $died = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($died);
        $this->connection->affectingStatement(
            "UPDATE scheduler_runs SET lease_expires_at = '2000-01-01 00:00:00.000000' WHERE id = ?",
            [$died->id],
        );

        $adopted = $this->store->claim($definition, '2026-09-05T18:00:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($adopted);
        self::assertSame($died->id, $adopted->id);
        self::assertSame('2026-09-05T12:00:00+00:00', $adopted->tick);
        self::assertSame(2, $adopted->attempt);
        self::assertSame('box-2:200', $adopted->claimedBy);

        $log = json_decode((string) $adopted->attempts, true);
        self::assertIsArray($log);

        $first = $log[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(1, $first['attempt']);
        self::assertSame('box-1:100', $first['claimed_by']);
    }

    #[Test]
    public function aDamagedScheduleRowIsSkippedNotFatal(): void
    {
        $this->storedDefinition();
        $this->connection->affectingStatement(
            "UPDATE scheduler_schedules SET timezone = 'Mars/Olympus' WHERE id = ?",
            ['every-minute'],
        );

        self::assertSame([], $this->store->enabledDefinitions());
    }

    #[Test]
    public function aClaimAgainstAMissingScheduleRefuses(): void
    {
        $ghost = new ScheduleDefinition(
            id: 'never-stored',
            command: 'dot ghost',
            pattern: Pattern::every('60s'),
        );

        self::assertNull($this->store->claim($ghost, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5));
    }

    #[Test]
    public function runsListNewestFirstAndFilterBySchedule(): void
    {
        $first = $this->storedDefinition(id: 'first');
        $second = $this->storedDefinition(id: 'second');

        foreach (['12:34', '12:35'] as $minute) {
            foreach ([$first, $second] as $definition) {
                $run = $this->store->claim($definition, '2026-09-05T' . $minute . ':00+00:00', 'box-1:100', 'box-1', 60, 5);
                self::assertNotNull($run);
                $this->store->finish($run, new RunOutcome(RunState::Succeeded, 0, 5, null, null));
            }
        }

        $all = $this->store->runs(null, 10);
        self::assertCount(4, $all);
        self::assertTrue($all[0]->id > $all[1]->id && $all[2]->id > $all[3]->id);

        $filtered = $this->store->runs('first', 10);
        self::assertCount(2, $filtered);
        self::assertTrue($filtered[0]->id > $filtered[1]->id);

        self::assertCount(1, $this->store->runs('first', 1));
    }

    #[Test]
    public function pruningClosesDiedFiresAndDeletesOnlyOldTerminalHistory(): void
    {
        $definition = $this->storedDefinition(id: 'prune-demo', overlap: OverlapPolicy::Allow);
        $past = static fn(int $seconds): string => gmdate('Y-m-d H:i:s', time() - $seconds) . '.000000';

        $live = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-1:100', 'box-1', 60, 5);
        $dying = $this->store->claim($definition, '2026-09-05T12:00:10+00:00', 'box-1:100', 'box-1', 60, 5);
        $fresh = $this->store->claim($definition, '2026-09-05T12:00:20+00:00', 'box-1:100', 'box-1', 60, 5);
        $ancient = $this->store->claim($definition, '2026-09-05T12:00:30+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($live);
        self::assertNotNull($dying);
        self::assertNotNull($fresh);
        self::assertNotNull($ancient);
        self::assertTrue($this->store->finish($ancient, new RunOutcome(RunState::Succeeded, 0, 5, null, null)));

        $this->connection->affectingStatement(
            'UPDATE scheduler_runs SET lease_expires_at = ? WHERE id = ?',
            [$past(400), $dying->id],
        );
        $this->connection->affectingStatement(
            'UPDATE scheduler_runs SET lease_expires_at = ? WHERE id = ?',
            [$past(10), $fresh->id],
        );
        $this->connection->affectingStatement(
            'UPDATE scheduler_runs SET started_at = ?, finished_at = ? WHERE id = ?',
            [$past(40 * 86400), $past(40 * 86400), $ancient->id],
        );

        self::assertSame(1, $this->store->closeStaleRuns(300));

        $closed = $this->store->run($dying->id);
        self::assertNotNull($closed);
        self::assertSame(RunState::Expired, $closed->state);
        self::assertNotNull($closed->finishedAt);
        self::assertStringContainsString('never adopted', (string) $closed->error);

        self::assertSame(RunState::Running, $this->store->run($fresh->id)?->state);
        self::assertSame(RunState::Running, $this->store->run($live->id)?->state);

        self::assertSame(1, $this->store->pruneRuns(30));

        $remaining = $this->store->runs('prune-demo', 10);

        self::assertCount(3, $remaining);
        self::assertNull($this->store->run($ancient->id));
        self::assertSame(RunState::Running, $this->store->run($live->id)?->state);
        self::assertSame(RunState::Expired, $this->store->run($dying->id)?->state);
    }

    #[Test]
    public function theCatalogMirrorsExactlyWhatIsDeclared(): void
    {
        $first = $this->store->reconcileCatalog([
            new CatalogEntry('demo:seeded', 'dot demo:seeded', 'First'),
            new CatalogEntry('demo:other', 'dot demo:other', null),
        ]);

        self::assertSame(['added' => 2, 'updated' => 0, 'removed' => 0], $first);
        self::assertCount(2, $this->store->catalogEntries());

        $second = $this->store->reconcileCatalog([
            new CatalogEntry('demo:seeded', 'dot demo:seeded --v2', 'Changed'),
            new CatalogEntry('demo:third', 'dot demo:third', null),
        ]);

        self::assertSame(['added' => 1, 'updated' => 1, 'removed' => 1], $second);

        $names = [];

        foreach ($this->store->catalogEntries() as $entry) {
            $names[$entry->name] = $entry->command;
        }

        self::assertSame(['demo:seeded' => 'dot demo:seeded --v2', 'demo:third' => 'dot demo:third'], $names);
    }

    #[Test]
    public function everyAbandonedAttemptStaysInTheLog(): void
    {
        $this->store = new RelationalStore($this->connection, maxAttempts: 6);
        $definition = $this->storedDefinition();
        $run = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $run = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', sprintf('box-%d:100', $attempt), 'box', 60, 5);

            self::assertNotNull($run);
            self::assertSame($attempt, $run->attempt);
            $this->expire($run);
        }

        self::assertNotNull($run);

        $log = json_decode((string) $run->attempts, true);

        self::assertIsArray($log);
        self::assertSame([1, 2, 3, 4], array_column($log, 'attempt'));
        self::assertSame(['box-1:100', 'box-2:100', 'box-3:100', 'box-4:100'], array_column($log, 'claimed_by'));
    }

    #[Test]
    public function aFireAbandonedPastTheCeilingIsRecordedFailed(): void
    {
        $this->store = new RelationalStore($this->connection, maxAttempts: 2);
        $definition = $this->storedDefinition();
        $first = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($first);
        $this->expire($first);

        $second = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($second);
        self::assertSame(2, $second->attempt);
        $this->expire($second);

        self::assertNull($this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-3:300', 'box-3', 60, 5));

        $spent = $this->store->run($first->id);

        self::assertNotNull($spent);
        self::assertSame(RunState::Failed, $spent->state);
        self::assertNotNull($spent->finishedAt);
        self::assertSame('Abandoned 2 times without completing; not attempted again.', $spent->error);

        $later = $this->store->claim($definition, '2026-09-05T13:00:00+00:00', 'box-3:300', 'box-3', 60, 5);

        self::assertNotNull($later);
        self::assertSame(1, $later->attempt);
    }

    #[Test]
    public function aStaleRunPastTheCeilingYieldsToTheDueFire(): void
    {
        $this->store = new RelationalStore($this->connection, maxAttempts: 2);
        $definition = $this->storedDefinition();
        $first = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($first);
        $this->expire($first);

        $adopted = $this->store->claim($definition, '2026-09-05T13:00:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($adopted);
        self::assertSame($first->id, $adopted->id);
        self::assertSame(2, $adopted->attempt);
        $this->expire($adopted);

        $due = $this->store->claim($definition, '2026-09-05T14:00:00+00:00', 'box-3:300', 'box-3', 60, 5);

        self::assertNotNull($due);
        self::assertNotSame($first->id, $due->id);
        self::assertSame('2026-09-05T14:00:00+00:00', $due->tick);
        self::assertSame(RunState::Failed, $this->store->run($first->id)?->state);
    }

    #[Test]
    public function aLiveRunAtTheCeilingIsLeftToFinish(): void
    {
        $this->store = new RelationalStore($this->connection, maxAttempts: 2);
        $definition = $this->storedDefinition();
        $first = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-1:100', 'box-1', 60, 5);

        self::assertNotNull($first);
        $this->expire($first);

        $last = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-2:200', 'box-2', 60, 5);

        self::assertNotNull($last);
        self::assertNull($this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box-3:300', 'box-3', 60, 5));
        self::assertSame(RunState::Running, $this->store->run($last->id)?->state);
        self::assertTrue($this->store->finish($last, new RunOutcome(RunState::Succeeded, 0, 5, null, null)));
    }

    #[Test]
    public function aCeilingBelowOneIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new RelationalStore($this->connection, maxAttempts: 0);
    }

    #[Test]
    public function aFailureIsFoundWhateverTheNewestRunDid(): void
    {
        $flaky = $this->storedDefinition(id: 'flaky', overlap: OverlapPolicy::Allow);
        $this->storedDefinition(id: 'clean');
        $failed = $this->store->claim($flaky, '2026-09-05T12:00:00+00:00', 'box:1', 'box', 60, 5);

        self::assertNotNull($failed);
        self::assertTrue($this->store->finish($failed, new RunOutcome(RunState::Failed, 7, 5, null, 'Exited with code 7.')));

        $succeeded = $this->store->claim($flaky, '2026-09-05T12:01:00+00:00', 'box:1', 'box', 60, 5);

        self::assertNotNull($succeeded);
        self::assertTrue($this->store->finish($succeeded, new RunOutcome(RunState::Succeeded, 0, 5, null, null)));
        self::assertSame(RunState::Succeeded, $this->store->latestRun('flaky')?->state);
        self::assertTrue($this->store->hasRecentFailure('flaky', 3600));
        self::assertFalse($this->store->hasRecentFailure('clean', 3600));

        $this->connection->affectingStatement('UPDATE scheduler_runs SET started_at = ? WHERE id = ?', [$this->secondsAgo(7200), $failed->id]);

        self::assertFalse($this->store->hasRecentFailure('flaky', 3600));
        self::assertTrue($this->store->hasRecentFailure('flaky', 3 * 3600));

        $this->connection->affectingStatement('UPDATE scheduler_runs SET started_at = ? WHERE id = ?', [$failed->startedAt, $failed->id]);

        $died = $this->store->claim($flaky, '2026-09-05T12:02:00+00:00', 'box:1', 'box', 60, 5);

        self::assertNotNull($died);
        $this->connection->affectingStatement('UPDATE scheduler_runs SET lease_expires_at = ? WHERE id = ?', [$this->secondsAgo(400), $died->id]);
        self::assertSame(1, $this->store->closeStaleRuns(300));
        self::assertTrue($this->store->hasRecentFailure('flaky', 3600));
    }

    #[Test]
    public function anUnchangedScheduleIsNotRewritten(): void
    {
        $definition = $this->storedDefinition();

        $this->connection->affectingStatement(
            'UPDATE scheduler_schedules SET created_at = ?, updated_at = ? WHERE id = ?',
            ['2026-01-01 00:00:00', '2026-01-01 00:00:00', 'every-minute'],
        );

        $this->store->saveDefinition($definition);

        self::assertSame('2026-01-01 00:00:00', $this->scheduleStamp('updated_at'));

        $this->store->saveDefinition($definition->withEnabled(false));

        self::assertNotSame('2026-01-01 00:00:00', $this->scheduleStamp('updated_at'));
        self::assertSame('2026-01-01 00:00:00', $this->scheduleStamp('created_at'));
    }

    #[Test]
    public function aScheduleKnowsWhenItBecameExpected(): void
    {
        $this->storedDefinition();

        $expectedSince = $this->store->definition('every-minute')?->expectedSince;

        self::assertNotNull($expectedSince);
        self::assertSame($this->scheduleStamp('updated_at'), $expectedSince->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $expectedSince->getTimezone()->getName());

        $this->connection->affectingStatement('UPDATE scheduler_schedules SET created_at = NULL, updated_at = NULL WHERE id = ?', ['every-minute']);

        self::assertNull($this->store->definition('every-minute')?->expectedSince);
    }

    #[Test]
    public function pruningGoesInBatchesByWhenARunStarted(): void
    {
        $definition = $this->storedDefinition(id: 'sweeper', overlap: OverlapPolicy::Allow);

        $this->seedTerminalRuns('sweeper', 5003, '2026-10-01 00:00:00.000000');

        $fresh = $this->store->claim($definition, '2026-09-05T12:00:00+00:00', 'box:1', 'box', 60, 5);

        self::assertNotNull($fresh);
        self::assertTrue($this->store->finish($fresh, new RunOutcome(RunState::Succeeded, 0, 5, null, null)));
        $this->connection->enableQueryLog();

        self::assertSame(5003, $this->store->pruneRuns(30));

        $deletes = array_filter(
            $this->connection->getQueryLog(),
            static fn(array $entry): bool => str_starts_with($entry['query'], 'DELETE'),
        );

        self::assertCount(2, $deletes);
        self::assertSame($fresh->id, $this->store->latestRun('sweeper')?->id);
        self::assertSame(0, $this->store->pruneRuns(30));
    }

    #[Test]
    public function aClaimInsideACallersTransactionNestsInsideIt(): void
    {
        $definition = $this->storedDefinition();
        $this->connection->beginTransaction();
        $run = null;

        try {
            $run = $this->store->claim($definition, '2026-09-05T12:34:00+00:00', 'box-1:100', 'box-1', 60, 5);
        } finally {
            $this->connection->rollBack();
        }

        self::assertNotNull($run);
        self::assertNull($this->store->run($run->id));
    }

    #[Test]
    public function installBringsAnOlderStoresIndexesUpToDate(): void
    {
        foreach (self::LATER_INDEXES as $index) {
            $this->dropIndex($index);
        }

        self::assertSame([], array_intersect(self::LATER_INDEXES, $this->indexNames()));
        self::assertSame(3, $this->schema->version());

        $this->schema->install();

        self::assertSame([], array_diff(self::LATER_INDEXES, $this->indexNames()));
        self::assertSame(3, $this->schema->version());
    }

    protected function storedDefinition(
        string $id = 'every-minute',
        OverlapPolicy $overlap = OverlapPolicy::Forbid,
    ): ScheduleDefinition {
        $definition = new ScheduleDefinition(
            id: $id,
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            overlap: $overlap,
        );
        $this->store->saveDefinition($definition);

        return $definition;
    }

    /**
     * Lets a run's lease lapse, as a dead runner's does.
     *
     * @param RunRecord $run The run whose lease is backdated
     *
     * @return void
     */
    protected function expire(RunRecord $run): void
    {
        $this->connection->affectingStatement(
            "UPDATE scheduler_runs SET lease_expires_at = '2000-01-01 00:00:00.000000' WHERE id = ?",
            [$run->id],
        );
    }

    /**
     * An environment setting of the test services, or its usual value.
     *
     * @param string $name The variable
     * @param string $usual What it is when the variable is not set
     *
     * @return string
     */
    protected function setting(string $name, string $usual): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $usual;
    }

    /**
     * @return string The store-format stamp of a moment seconds in the past.
     */
    protected function secondsAgo(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds) . '.000000';
    }

    /**
     * @param string $column A stamp column of the schedules table
     *
     * @return string The demo schedule's stamp in that column.
     */
    protected function scheduleStamp(string $column): string
    {
        $row = $this->connection->selectOne(sprintf('SELECT %s FROM scheduler_schedules WHERE id = ?', $column), ['every-minute']);

        return $row === null ? '' : Columns::string($row, $column);
    }

    /**
     * Writes finished runs straight into the table, far faster than claiming
     * each one.
     *
     * @param string $scheduleId The schedule the runs belong to
     * @param int $count How many runs to write
     * @param string $startedAt The start stamp every run carries
     *
     * @return void
     */
    protected function seedTerminalRuns(string $scheduleId, int $count, string $startedAt): void
    {
        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = [];
            $bindings = [];

            for ($i = $offset; $i < min($count, $offset + 500); $i++) {
                $rows[] = "(?, ?, 'succeeded', 1, 'box:1', 'box', ?, ?, '2026-10-01 00:01:00.000000', ?, 0, 5)";
                array_push($bindings, $scheduleId, sprintf('seed-%06d', $i), $startedAt, $startedAt, $startedAt);
            }

            $this->connection->affectingStatement(
                sprintf(
                    'INSERT INTO scheduler_runs (schedule_id, tick, state, attempt, claimed_by, server_host, started_at, heartbeat_at, lease_expires_at, finished_at, exit_code, duration_ms) VALUES %s',
                    implode(', ', $rows),
                ),
                $bindings,
            );
        }
    }

    /**
     * @return list<string> Every index the runs table carries.
     */
    protected function indexNames(): array
    {
        $sql = match ($this->connection->getDriverName()) {
            'mysql' => "SELECT index_name AS name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'scheduler_runs' GROUP BY index_name",
            'pgsql' => "SELECT indexname AS name FROM pg_indexes WHERE tablename = 'scheduler_runs'",
            default => "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'scheduler_runs'",
        };
        $names = [];

        foreach ($this->connection->select($sql)->all() as $row) {
            $names[] = Columns::string($row, 'name');
        }

        return $names;
    }

    /**
     * @return void
     */
    protected function dropIndex(string $index): void
    {
        $this->connection->statement(
            $this->connection->getDriverName() === 'mysql'
                ? 'ALTER TABLE scheduler_runs DROP INDEX ' . $index
                : 'DROP INDEX ' . $index,
        );
    }
}
