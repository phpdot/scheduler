<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration;

use DateTimeZone;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Exception\SchemaMismatchException;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
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
}
