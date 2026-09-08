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
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Exception\SchemaMismatchException;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;

final class RelationalStore implements SchedulerStoreInterface
{
    private const string STATE_RUNNING = 'running';

    private const string DEFINITION_COLUMNS = 'id, command, pattern, timezone, timeout_seconds, idle_timeout_seconds, overlap, grace_seconds, enabled, description';

    private readonly Dialect $dialect;

    private readonly SchedulerSchema $schema;

    private bool $schemaVerified = false;

    /**
     * @param DatabaseConnection $connection The shared store connection
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
    ) {
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

        return array_map($this->hydrateDefinition(...), $rows->all());
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

        return array_map($this->hydrateDefinition(...), $rows->all());
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
     * @return void
     */
    public function saveDefinition(ScheduleDefinition $definition): void
    {
        $this->ensureSchema();

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

        /**
         * @var RunRecord|null $claimed
         */
        $claimed = $this->connection->transaction(function (DatabaseConnection $conn) use ($definition, $tick, $serverId, $serverHost, $concurrencyLimit, $now, $nowPlus): RunRecord|null {
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

            $adopted = $conn->affectingStatement(
                sprintf(
                    "UPDATE %s SET state = '%s', attempt = attempt + 1, claimed_by = ?, server_host = ?, started_at = %s, heartbeat_at = %s, lease_expires_at = %s, finished_at = NULL, exit_code = NULL, duration_ms = NULL, output_tail = NULL, error = NULL WHERE schedule_id = ? AND tick = ? AND state = '%s' AND lease_expires_at <= %s",
                    SchedulerSchema::RUNS_TABLE,
                    self::STATE_RUNNING,
                    $now,
                    $now,
                    $nowPlus,
                    self::STATE_RUNNING,
                    $now,
                ),
                [$serverId, $serverHost, $definition->id, $tick],
            );

            if ($adopted === 1) {
                return $this->fetchByTick($conn, $definition->id, $tick);
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
     * @return int
     */
    public function pruneRuns(int $retentionDays): int
    {
        $this->ensureSchema();

        return $this->connection->affectingStatement(
            sprintf(
                "DELETE FROM %s WHERE state <> '%s' AND COALESCE(finished_at, started_at) <= %s",
                SchedulerSchema::RUNS_TABLE,
                self::STATE_RUNNING,
                $this->dialect->nowMinus($retentionDays * 86400),
            ),
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
