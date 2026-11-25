<?php

declare(strict_types=1);

/**
 * The relational claim protocol over phpdot/database: one guarded statement
 * per transition, every clock read from the store itself in UTC.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Contract\RecentFailuresInterface;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Exception\InvalidConfigurationException;
use PHPdot\Scheduler\Exception\SchemaMismatchException;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use Throwable;

final class RelationalStore implements RecentFailuresInterface, SchedulerStoreInterface
{
    public const int DEFAULT_MAX_ATTEMPTS = 3;

    private const string STATE_RUNNING = 'running';

    private const string DEFINITION_COLUMNS = 'id, command, pattern, timezone, timeout_seconds, idle_timeout_seconds, overlap, grace_seconds, enabled, description, created_at, updated_at';

    private const int ATTEMPT_LOG_LIMIT = 20;

    private const int PRUNE_BATCH_ROWS = 5000;

    private const string SPENT_MESSAGE = 'Abandoned %d times without completing; not attempted again.';

    /**
     * Where a prune pass starts: a start stamp earlier than any stored one,
     * given twice for the cursor's comparison, then the lowest id.
     *
     * @var list<string|int>
     */
    private const array PRUNE_OLDEST = ['', '', 0];

    private readonly Dialect $dialect;

    private readonly SchedulerSchema $schema;

    private bool $schemaVerified = false;

    /**
     * @param DatabaseConnection $connection The shared store connection
     * @param int $maxAttempts Times one fire may be handed to a runner before it is recorded failed instead of adopted
     *
     * @throws InvalidConfigurationException When the attempt ceiling is below one.
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ) {
        if ($maxAttempts < 1) {
            throw InvalidConfigurationException::forField('maxAttempts', 'it must be at least 1');
        }

        $this->dialect = new Dialect($connection->getDriverName());
        $this->schema = new SchedulerSchema($connection);
    }

    /**
     * @return list<ScheduleDefinition>
     */
    public function enabledDefinitions(): array
    {
        $this->ensureSchema();

        $rows = $this->connection->select(
            sprintf('SELECT %s FROM %s WHERE enabled = TRUE ORDER BY id', self::DEFINITION_COLUMNS, SchedulerSchema::SCHEDULES_TABLE),
        );

        return $this->hydrateAll($rows->all());
    }

    /**
     * @return list<ScheduleDefinition>
     */
    public function allDefinitions(): array
    {
        $this->ensureSchema();

        $rows = $this->connection->select(
            sprintf('SELECT %s FROM %s ORDER BY id', self::DEFINITION_COLUMNS, SchedulerSchema::SCHEDULES_TABLE),
        );

        return $this->hydrateAll($rows->all());
    }

    /**
     * @return ScheduleDefinition|null
     */
    public function definition(string $id): ScheduleDefinition|null
    {
        $this->ensureSchema();

        $row = $this->connection->selectOne(
            sprintf('SELECT %s FROM %s WHERE id = ?', self::DEFINITION_COLUMNS, SchedulerSchema::SCHEDULES_TABLE),
            [$id],
        );

        return $row === null ? null : $this->hydrateDefinition($row);
    }

    /**
     * Store a schedule, or leave the row untouched when it already says
     * exactly this: a schedule re-applied at every deploy must not look
     * redefined each time.
     *
     * @param ScheduleDefinition $definition The definition to insert or update
     *
     * @return void
     */
    public function saveDefinition(ScheduleDefinition $definition): void
    {
        $this->ensureSchema();

        if ($this->isStored($definition)) {
            return;
        }

        $stamp = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $bindings = [
            $definition->command,
            $definition->pattern->expression,
            $definition->timezone->getName(),
            $definition->timeoutSeconds,
            $definition->idleTimeoutSeconds,
            $definition->overlap->value,
            $definition->graceSeconds,
            $definition->enabled,
            $definition->description,
        ];

        $updated = $this->connection->affectingStatement(
            sprintf(
                'UPDATE %s SET command = ?, pattern = ?, timezone = ?, timeout_seconds = ?, idle_timeout_seconds = ?, overlap = ?, grace_seconds = ?, enabled = ?, description = ?, updated_at = ? WHERE id = ?',
                SchedulerSchema::SCHEDULES_TABLE,
            ),
            [...$bindings, $stamp, $definition->id],
        );

        if ($updated >= 1) {
            return;
        }

        $inserted = $this->connection->affectingStatement(
            sprintf(
                'INSERT INTO %s (id, command, pattern, timezone, timeout_seconds, idle_timeout_seconds, overlap, grace_seconds, enabled, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)%s',
                SchedulerSchema::SCHEDULES_TABLE,
                $this->dialect->insertNoopConflict('id'),
            ),
            [$definition->id, ...$bindings, $stamp, $stamp],
        );

        if ($inserted === 0) {
            $this->connection->affectingStatement(
                sprintf(
                    'UPDATE %s SET command = ?, pattern = ?, timezone = ?, timeout_seconds = ?, idle_timeout_seconds = ?, overlap = ?, grace_seconds = ?, enabled = ?, description = ?, updated_at = ? WHERE id = ?',
                    SchedulerSchema::SCHEDULES_TABLE,
                ),
                [...$bindings, $stamp, $definition->id],
            );
        }
    }

    /**
     * @return list<CatalogEntry>
     */
    public function catalogEntries(): array
    {
        $this->ensureSchema();

        $rows = $this->connection->select(
            sprintf('SELECT name, command, description FROM %s ORDER BY name', SchedulerSchema::CATALOG_TABLE),
        );

        return array_map(
            static fn(array $row): CatalogEntry => new CatalogEntry(
                Columns::string($row, 'name'),
                Columns::string($row, 'command'),
                Columns::nullableString($row, 'description'),
            ),
            $rows->all(),
        );
    }

    /**
     * @return array{added: int, updated: int, removed: int}
     */
    public function reconcileCatalog(array $entries): array
    {
        $this->ensureSchema();

        $existing = [];

        foreach ($this->catalogEntries() as $entry) {
            $existing[$entry->name] = $entry;
        }

        $stamp = (new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $incoming = [];
        $added = 0;
        $updated = 0;

        foreach ($entries as $entry) {
            $incoming[$entry->name] = $entry;
            $current = $existing[$entry->name] ?? null;

            if ($current === null) {
                $this->connection->affectingStatement(
                    sprintf('INSERT INTO %s (name, command, description, updated_at) VALUES (?, ?, ?, ?)', SchedulerSchema::CATALOG_TABLE),
                    [$entry->name, $entry->command, $entry->description, $stamp],
                );
                $added++;

                continue;
            }

            if ($current->command !== $entry->command || $current->description !== $entry->description) {
                $this->connection->affectingStatement(
                    sprintf('UPDATE %s SET command = ?, description = ?, updated_at = ? WHERE name = ?', SchedulerSchema::CATALOG_TABLE),
                    [$entry->command, $entry->description, $stamp, $entry->name],
                );
                $updated++;
            }
        }

        $removedNames = array_diff(array_keys($existing), array_keys($incoming));
        $removed = 0;

        foreach ($removedNames as $name) {
            $this->connection->affectingStatement(
                sprintf('DELETE FROM %s WHERE name = ?', SchedulerSchema::CATALOG_TABLE),
                [$name],
            );
            $removed++;
        }

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * @return bool
     */
    public function deleteDefinition(string $id): bool
    {
        $this->ensureSchema();

        return $this->connection->affectingStatement(
            sprintf('DELETE FROM %s WHERE id = ?', SchedulerSchema::SCHEDULES_TABLE),
            [$id],
        ) === 1;
    }

    /**
     * @return RunRecord|null
     */
    public function claim(
        ScheduleDefinition $definition,
        string $tick,
        string $serverId,
        string $serverHost,
        int $leaseTtlSeconds,
        int $concurrencyLimit,
    ): RunRecord|null {
        $this->ensureSchema();
        $now = $this->dialect->now();
        $nowPlus = $this->dialect->nowPlus($leaseTtlSeconds);
        $pin = $this->connection->transactionLevel() === 0 ? $this->dialect->pinReadCommitted() : null;

        /**
         * @var RunRecord|null $claimed
         */
        $claimed = $this->connection->transaction(function (DatabaseConnection $conn) use ($definition, $tick, $serverId, $serverHost, $concurrencyLimit, $now, $nowPlus, $pin): RunRecord|null {
            if ($pin !== null) {
                $conn->statement($pin);
            }

            if ($conn->selectOne($this->dialect->lockScheduleRow(SchedulerSchema::SCHEDULES_TABLE), [$definition->id]) === null) {
                return null;
            }

            $hostLoad = $conn->selectOne(
                sprintf("SELECT COUNT(*) AS live FROM %s WHERE server_host = ? AND state = '%s' AND lease_expires_at > %s", SchedulerSchema::RUNS_TABLE, self::STATE_RUNNING, $now),
                [$serverHost],
            );

            if ($hostLoad !== null && Columns::int($hostLoad, 'live') >= $concurrencyLimit) {
                return null;
            }

            if ($definition->overlap === OverlapPolicy::Forbid && $conn->selectOne(
                sprintf("SELECT id FROM %s WHERE schedule_id = ? AND state = '%s' AND lease_expires_at > %s LIMIT 1", SchedulerSchema::RUNS_TABLE, self::STATE_RUNNING, $now),
                [$definition->id],
            ) !== null) {
                return null;
            }

            $abandoned = $this->fetchByTick($conn, $definition->id, $tick);
            $adopted = $conn->affectingStatement(
                sprintf(
                    "UPDATE %s SET state = '%s', attempt = attempt + 1, claimed_by = ?, server_host = ?, started_at = %s, heartbeat_at = %s, lease_expires_at = %s, attempts = ?, finished_at = NULL, exit_code = NULL, duration_ms = NULL, output_tail = NULL, error = NULL WHERE schedule_id = ? AND tick = ? AND state = '%s' AND lease_expires_at <= %s AND attempt < ?",
                    SchedulerSchema::RUNS_TABLE,
                    self::STATE_RUNNING,
                    $now,
                    $now,
                    $nowPlus,
                    self::STATE_RUNNING,
                    $now,
                ),
                [$serverId, $serverHost, $this->attemptsWith($abandoned), $definition->id, $tick, $this->maxAttempts],
            );

            if ($adopted === 1) {
                return $this->fetchByTick($conn, $definition->id, $tick);
            }

            $this->retireIfSpent($conn, $abandoned, $now);

            $stale = $this->adoptStaleRun($conn, $definition, $serverId, $serverHost, $now, $nowPlus);

            if ($stale !== null) {
                return $stale;
            }

            $conn->affectingStatement(
                sprintf(
                    "INSERT INTO %s (schedule_id, tick, state, attempt, claimed_by, server_host, started_at, heartbeat_at, lease_expires_at) VALUES (?, ?, '%s', 1, ?, ?, %s, %s, %s)%s",
                    SchedulerSchema::RUNS_TABLE,
                    self::STATE_RUNNING,
                    $now,
                    $now,
                    $nowPlus,
                    $this->dialect->insertNoopConflict('schedule_id', 'tick'),
                ),
                [$definition->id, $tick, $serverId, $serverHost],
            );

            $run = $this->fetchByTick($conn, $definition->id, $tick);

            if ($run !== null && $run->claimedBy === $serverId && $run->attempt === 1 && $run->state === RunState::Running) {
                return $run;
            }

            return null;
        }, 3);

        return $claimed;
    }

    /**
     * Take over the newest expired running row for this schedule, whatever its
     * tick, appending the attempt it replaces to the row's attempts log; a
     * run the attempt ceiling has spent is recorded failed in its place.
     *
     * @param DatabaseConnection $conn The claim's transaction
     * @param ScheduleDefinition $definition The schedule whose stale run is taken over
     * @param string $serverId The adopting server's identity
     * @param string $serverHost The adopting host's name
     * @param string $now The store's now expression
     * @param string $nowPlus The store's lease-expiry expression
     *
     * @return RunRecord|null The adopted run, when one was taken.
     */
    private function adoptStaleRun(
        DatabaseConnection $conn,
        ScheduleDefinition $definition,
        string $serverId,
        string $serverHost,
        string $now,
        string $nowPlus,
    ): RunRecord|null {
        $stale = $conn->selectOne(
            sprintf(
                "SELECT * FROM %s WHERE schedule_id = ? AND state = '%s' AND lease_expires_at <= %s ORDER BY id DESC LIMIT 1",
                SchedulerSchema::RUNS_TABLE,
                self::STATE_RUNNING,
                $now,
            ),
            [$definition->id],
        );

        if ($stale === null) {
            return null;
        }

        $abandoned = $this->hydrateRun($stale);

        if ($abandoned->attempt >= $this->maxAttempts) {
            $this->retireIfSpent($conn, $abandoned, $now);

            return null;
        }

        $adopted = $conn->affectingStatement(
            sprintf(
                "UPDATE %s SET state = '%s', attempt = attempt + 1, claimed_by = ?, server_host = ?, started_at = %s, heartbeat_at = %s, lease_expires_at = %s, attempts = ?, finished_at = NULL, exit_code = NULL, duration_ms = NULL, output_tail = NULL, error = 'Abandoned: its lease expired before it finished.' WHERE id = ? AND attempt = ? AND state = '%s'",
                SchedulerSchema::RUNS_TABLE,
                self::STATE_RUNNING,
                $now,
                $now,
                $nowPlus,
                self::STATE_RUNNING,
            ),
            [
                $serverId,
                $serverHost,
                $this->attemptsWith($abandoned),
                Columns::int($stale, 'id'),
                Columns::int($stale, 'attempt'),
            ],
        );

        if ($adopted !== 1) {
            return null;
        }

        $adoptedRow = $conn->selectOne(
            sprintf('SELECT * FROM %s WHERE id = ?', SchedulerSchema::RUNS_TABLE),
            [Columns::int($stale, 'id')],
        );

        return $adoptedRow === null ? null : $this->hydrateRun($adoptedRow);
    }

    /**
     * Record as failed a run the attempt ceiling has spent, so the fire it
     * held becomes history instead of a row every later claim takes up again.
     * An expired lease is part of the guard: a live run at the ceiling runs on.
     *
     * @param DatabaseConnection $conn The claim's transaction
     * @param RunRecord|null $run The run the claim could not adopt
     * @param string $now The store's now expression
     *
     * @return void
     */
    private function retireIfSpent(DatabaseConnection $conn, RunRecord|null $run, string $now): void
    {
        if ($run === null || $run->state !== RunState::Running || $run->attempt < $this->maxAttempts) {
            return;
        }

        $conn->affectingStatement(
            sprintf(
                "UPDATE %s SET state = 'failed', finished_at = %s, error = ? WHERE id = ? AND attempt = ? AND state = '%s' AND lease_expires_at <= %s",
                SchedulerSchema::RUNS_TABLE,
                $now,
                self::STATE_RUNNING,
                $now,
            ),
            [sprintf(self::SPENT_MESSAGE, $run->attempt), $run->id, $run->attempt],
        );
    }

    /**
     * The attempts log with the abandoned attempt appended, newest last and
     * trimmed to the limit so one row can never outgrow its column.
     *
     * @param RunRecord|null $abandoned The attempt being replaced, null on a first claim
     *
     * @return string The JSON attempts log.
     */
    private function attemptsWith(RunRecord|null $abandoned): string
    {
        if ($abandoned === null) {
            return '[]';
        }

        $log = json_decode((string) $abandoned->attempts, true);
        $entries = is_array($log) ? $log : [];
        $entries[] = [
            'attempt' => $abandoned->attempt,
            'claimed_by' => $abandoned->claimedBy,
            'started_at' => $abandoned->startedAt,
            'exit_code' => $abandoned->exitCode,
            'duration_ms' => $abandoned->durationMs,
            'error' => $abandoned->error,
        ];

        $encoded = json_encode(
            array_values(array_slice($entries, -self::ATTEMPT_LOG_LIMIT)),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '[]' : $encoded;
    }

    /**
     * The run row a schedule's tick identifies, whoever holds it now.
     *
     * @param string $scheduleId The schedule the tick belongs to
     * @param string $tick The fire the row records
     *
     * @return RunRecord|null The row, null when the tick never fired.
     */
    public function runByTick(string $scheduleId, string $tick): RunRecord|null
    {
        $this->ensureSchema();

        return $this->fetchByTick($this->connection, $scheduleId, $tick);
    }

    /**
     * @return RunRecord|null
     */
    public function latestRun(string $scheduleId): RunRecord|null
    {
        $this->ensureSchema();

        $row = $this->connection->selectOne(
            sprintf('SELECT * FROM %s WHERE schedule_id = ? ORDER BY id DESC LIMIT 1', SchedulerSchema::RUNS_TABLE),
            [$scheduleId],
        );

        return $row === null ? null : $this->hydrateRun($row);
    }

    /**
     * @return bool
     */
    public function heartbeat(RunRecord $run, int $leaseTtlSeconds): bool
    {
        $this->ensureSchema();

        return $this->connection->affectingStatement(
            sprintf(
                "UPDATE %s SET heartbeat_at = %s, lease_expires_at = %s WHERE id = ? AND attempt = ? AND state = '%s'",
                SchedulerSchema::RUNS_TABLE,
                $this->dialect->now(),
                $this->dialect->nowPlus($leaseTtlSeconds),
                self::STATE_RUNNING,
            ),
            [$run->id, $run->attempt],
        ) === 1;
    }

    /**
     * @return bool
     */
    public function finish(RunRecord $run, RunOutcome $outcome): bool
    {
        $this->ensureSchema();

        return $this->connection->affectingStatement(
            sprintf(
                "UPDATE %s SET state = ?, finished_at = %s, exit_code = ?, duration_ms = ?, output_tail = ?, error = ? WHERE id = ? AND attempt = ? AND state = '%s'",
                SchedulerSchema::RUNS_TABLE,
                $this->dialect->now(),
                self::STATE_RUNNING,
            ),
            [$outcome->state->value, $outcome->exitCode, $outcome->durationMs, $outcome->outputTail, $outcome->error, $run->id, $run->attempt],
        ) === 1;
    }

    /**
     * @return bool
     */
    public function hasLiveRun(string $scheduleId): bool
    {
        $this->ensureSchema();

        return $this->connection->selectOne(
            sprintf("SELECT id FROM %s WHERE schedule_id = ? AND state = '%s' AND lease_expires_at > %s LIMIT 1", SchedulerSchema::RUNS_TABLE, self::STATE_RUNNING, $this->dialect->now()),
            [$scheduleId],
        ) !== null;
    }

    /**
     * Answered whatever run happens to be the schedule's newest.
     *
     * @param string $scheduleId The schedule whose history is asked
     * @param int $windowSeconds How far back a failure still counts
     *
     * @return bool True when a failed or expired run started inside the window.
     */
    public function hasRecentFailure(string $scheduleId, int $windowSeconds): bool
    {
        $this->ensureSchema();

        return $this->connection->selectOne(
            sprintf(
                "SELECT 1 FROM %s WHERE schedule_id = ? AND state IN ('%s', '%s') AND started_at >= ? LIMIT 1",
                SchedulerSchema::RUNS_TABLE,
                RunState::Failed->value,
                RunState::Expired->value,
            ),
            [$scheduleId, $this->cutoff($windowSeconds)],
        ) !== null;
    }

    /**
     * A moment this many seconds behind the store's clock, read once and
     * handed back as a literal: a boundary the engine can seek to, which a
     * clock expression written into the comparison is not.
     *
     * @param int $secondsBehind How far behind the store's clock
     *
     * @return string The moment in the store's stamp format.
     */
    private function cutoff(int $secondsBehind): string
    {
        $moment = $this->connection->selectOne(sprintf('SELECT %s AS cutoff', $this->dialect->nowMinus($secondsBehind)));

        return $moment === null ? '' : Columns::string($moment, 'cutoff');
    }

    /**
     * @return int
     */
    public function liveRunCountForHost(string $serverHost): int
    {
        $this->ensureSchema();

        $row = $this->connection->selectOne(
            sprintf("SELECT COUNT(*) AS live FROM %s WHERE server_host = ? AND state = '%s' AND lease_expires_at > %s", SchedulerSchema::RUNS_TABLE, self::STATE_RUNNING, $this->dialect->now()),
            [$serverHost],
        );

        return $row === null ? 0 : Columns::int($row, 'live');
    }

    /**
     * @return int
     */
    public function closeStaleRuns(int $graceSeconds): int
    {
        $this->ensureSchema();

        return $this->connection->affectingStatement(
            sprintf(
                "UPDATE %s SET state = 'expired', finished_at = %s, error = 'Lease expired without completion; the fire was never adopted.' WHERE state = '%s' AND lease_expires_at <= %s",
                SchedulerSchema::RUNS_TABLE,
                $this->dialect->now(),
                self::STATE_RUNNING,
                $this->dialect->nowMinus($graceSeconds),
            ),
        );
    }

    /**
     * The retention window is measured from each run's start, a column every
     * row carries. Batches read their slice by cursor and delete by id, so a
     * large history costs many short statements rather than one long one.
     *
     * @return int
     */
    public function pruneRuns(int $retentionDays): int
    {
        $this->ensureSchema();
        $candidates = $this->dialect->pruneCandidates(SchedulerSchema::RUNS_TABLE, max(1, $retentionDays) * 86400, self::PRUNE_BATCH_ROWS);
        $deleted = 0;
        $cursor = self::PRUNE_OLDEST;

        do {
            $rows = $this->connection->select($candidates, $cursor)->all();
            $deleted += $this->deleteRuns(array_map(static fn(array $row): int => Columns::int($row, 'id'), $rows));
            $last = end($rows);
            $cursor = $last === false ? self::PRUNE_OLDEST : [Columns::string($last, 'started_at'), Columns::string($last, 'started_at'), Columns::int($last, 'id')];
        } while (count($rows) === self::PRUNE_BATCH_ROWS);

        return $deleted;
    }

    /**
     * @param list<int> $ids The runs to delete, at most one batch
     *
     * @return int How many were deleted.
     */
    private function deleteRuns(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->connection->affectingStatement(
            sprintf(
                'DELETE FROM %s WHERE id IN (%s)',
                SchedulerSchema::RUNS_TABLE,
                implode(', ', array_fill(0, count($ids), '?')),
            ),
            $ids,
        );
    }

    /**
     * @return RunRecord|null
     */
    public function run(int $id): RunRecord|null
    {
        $this->ensureSchema();

        $row = $this->connection->selectOne(
            sprintf('SELECT * FROM %s WHERE id = ?', SchedulerSchema::RUNS_TABLE),
            [$id],
        );

        return $row === null ? null : $this->hydrateRun($row);
    }

    /**
     * @return list<RunRecord>
     */
    public function runs(string|null $scheduleId, int $limit): array
    {
        $this->ensureSchema();

        if ($scheduleId === null) {
            $rows = $this->connection->select(
                sprintf('SELECT * FROM %s ORDER BY id DESC LIMIT ?', SchedulerSchema::RUNS_TABLE),
                [$limit],
            );
        } else {
            $rows = $this->connection->select(
                sprintf('SELECT * FROM %s WHERE schedule_id = ? ORDER BY id DESC LIMIT ?', SchedulerSchema::RUNS_TABLE),
                [$scheduleId, $limit],
            );
        }

        return array_map($this->hydrateRun(...), $rows->all());
    }

    /**
     * @param DatabaseConnection $conn The transaction's connection
     * @param string $scheduleId The schedule claiming
     * @param string $tick The tick claimed
     *
     * @return RunRecord|null
     */
    private function fetchByTick(DatabaseConnection $conn, string $scheduleId, string $tick): RunRecord|null
    {
        $row = $conn->selectOne(
            sprintf('SELECT * FROM %s WHERE schedule_id = ? AND tick = ?', SchedulerSchema::RUNS_TABLE),
            [$scheduleId, $tick],
        );

        return $row === null ? null : $this->hydrateRun($row);
    }

    /**
     * Hydrate every schedule that parses: one damaged row is logged and
     * skipped, never allowed to stop every schedule on the host.
     *
     * @param list<array<string, mixed>> $rows scheduler_schedules rows
     *
     * @return list<ScheduleDefinition> The rows that parsed.
     */
    private function hydrateAll(array $rows): array
    {
        $definitions = [];

        foreach ($rows as $row) {
            $id = Columns::string($row, 'id');

            try {
                $definitions[] = $this->hydrateDefinition($row);
            } catch (Throwable $failure) {
                error_log(sprintf('[scheduler] schedule %s skipped, its row does not parse: %s', $id, $failure->getMessage()));
            }
        }

        return $definitions;
    }

    /**
     * Whether the stored row already says exactly this schedule. A row that
     * fails to read is never a match: saving over it is what repairs it.
     *
     * @param ScheduleDefinition $definition The definition about to be saved
     *
     * @return bool
     */
    private function isStored(ScheduleDefinition $definition): bool
    {
        try {
            $stored = $this->definition($definition->id);
        } catch (Throwable) {
            return false;
        }

        return $stored !== null && $stored->matches($definition);
    }

    /**
     * A schedule is expected to have fired only since it was last written:
     * its definition or its resumption. Older stores carry no stamp and stay
     * expected as before.
     *
     * @param array<string, mixed> $row A scheduler_schedules row
     *
     * @return ScheduleDefinition
     */
    private function hydrateDefinition(array $row): ScheduleDefinition
    {
        $timezone = Columns::string($row, 'timezone');

        return new ScheduleDefinition(
            id: Columns::string($row, 'id'),
            command: Columns::string($row, 'command'),
            pattern: new Pattern(Columns::string($row, 'pattern')),
            timezone: new DateTimeZone($timezone === '' ? 'UTC' : $timezone),
            timeoutSeconds: Columns::int($row, 'timeout_seconds'),
            idleTimeoutSeconds: Columns::nullableInt($row, 'idle_timeout_seconds'),
            overlap: OverlapPolicy::from(Columns::string($row, 'overlap')),
            graceSeconds: Columns::int($row, 'grace_seconds'),
            enabled: Columns::bool($row, 'enabled'),
            description: Columns::nullableString($row, 'description'),
            expectedSince: Columns::instant($row, 'updated_at') ?? Columns::instant($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $row A scheduler_runs row
     *
     * @return RunRecord
     */
    private function hydrateRun(array $row): RunRecord
    {
        return new RunRecord(
            id: Columns::int($row, 'id'),
            scheduleId: Columns::string($row, 'schedule_id'),
            tick: Columns::string($row, 'tick'),
            state: RunState::from(Columns::string($row, 'state')),
            attempt: Columns::int($row, 'attempt'),
            claimedBy: Columns::string($row, 'claimed_by'),
            serverHost: Columns::string($row, 'server_host'),
            startedAt: Columns::string($row, 'started_at'),
            heartbeatAt: Columns::nullableString($row, 'heartbeat_at'),
            leaseExpiresAt: Columns::string($row, 'lease_expires_at'),
            finishedAt: Columns::nullableString($row, 'finished_at'),
            exitCode: Columns::nullableInt($row, 'exit_code'),
            durationMs: Columns::nullableInt($row, 'duration_ms'),
            outputTail: Columns::nullableString($row, 'output_tail'),
            error: Columns::nullableString($row, 'error'),
            attempts: Columns::nullableString($row, 'attempts'),
        );
    }

    /**
     * @throws SchemaMismatchException When the schema is absent or from another version.
     *
     * @return void
     */
    private function ensureSchema(): void
    {
        if (!$this->schemaVerified) {
            $this->schema->assertInstalled();
            $this->schemaVerified = true;
        }
    }
}
