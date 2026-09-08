<?php

declare(strict_types=1);

/**
 * The storage seam: definitions, claims, and run history behind one contract,
 * so any store that can make claim() atomic can carry the scheduler.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Contract;

use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;

interface SchedulerStoreInterface
{
    /**
     * @return list<ScheduleDefinition> Every enabled definition, ordered by id.
     */
    public function enabledDefinitions(): array;

    /**
     * @return list<ScheduleDefinition> Every stored definition, ordered by id.
     */
    public function allDefinitions(): array;

    /**
     * @return list<CatalogEntry> Every schedulable command, ordered by name.
     */
    public function catalogEntries(): array;

    /**
     * Make the catalog mirror the given entries exactly — adding, updating,
     * and removing — and report how many of each happened.
     *
     * @param list<CatalogEntry> $entries The schedulable commands that exist
     *
     * @return array{added: int, updated: int, removed: int}
     */
    public function reconcileCatalog(array $entries): array;

    /**
     * @param string $id The schedule identifier
     *
     * @return ScheduleDefinition|null The definition, null when absent.
     */
    public function definition(string $id): ScheduleDefinition|null;

    /**
     * @param ScheduleDefinition $definition The definition to insert or update
     *
     * @return void
     */
    public function saveDefinition(ScheduleDefinition $definition): void;

    /**
     * @param string $id The schedule identifier
     *
     * @return bool True when a definition was deleted.
     */
    public function deleteDefinition(string $id): bool;

    /**
     * Atomically claim one fire of a schedule for this server. The whole
     * decision — overlap policy, host concurrency cap, insert-or-adopt — is
     * one storage-level transaction, so across every server ticking only one
     * claim can win.
     *
     * @param ScheduleDefinition $definition The schedule claiming its fire
     * @param string $tick The tick identifier being claimed
     * @param string $serverId The claiming server's identity
     * @param string $serverHost The claiming server's host
     * @param int $leaseTtlSeconds How long the claim lives without a heartbeat
     * @param int $concurrencyLimit Max live runs this host may hold
     *
     * @return RunRecord|null The claimed run, null when another server holds it.
     */
    public function claim(
        ScheduleDefinition $definition,
        string $tick,
        string $serverId,
        string $serverHost,
        int $leaseTtlSeconds,
        int $concurrencyLimit,
    ): RunRecord|null;

    /**
     * Renew a claim's lease; false once a newer attempt has taken the row.
     *
     * @param RunRecord $run The run whose lease is renewed
     * @param int $leaseTtlSeconds How long the claim now lives without renewal
     *
     * @return bool True when the lease was renewed.
     */
    public function heartbeat(RunRecord $run, int $leaseTtlSeconds): bool;

    /**
     * Record how an execution ended; false once a newer attempt has taken the
     * row — a zombie's write touches nothing.
     *
     * @param RunRecord $run The run being completed
     * @param RunOutcome $outcome The terminal state and its evidence
     *
     * @return bool True when the outcome was recorded.
     */
    public function finish(RunRecord $run, RunOutcome $outcome): bool;

    /**
     * @param string $scheduleId The schedule identifier
     *
     * @return bool True when the schedule has a run with an unexpired lease.
     */
    public function hasLiveRun(string $scheduleId): bool;

    /**
     * @param string $serverHost The host whose live runs are counted
     *
     * @return int Runs this host holds with unexpired leases.
     */
    public function liveRunCountForHost(string $serverHost): int;

    /**
     * Close runs whose leases expired long ago without an adoption, marking
     * them Expired — history for fires whose runner died and was never
     * succeeded. A freshly adopted run carries a future lease, so a generous
     * grace cannot race a live adoption.
     *
     * @param int $graceSeconds How far past lease expiry before a run closes
     *
     * @return int How many runs were closed.
     */
    public function closeStaleRuns(int $graceSeconds): int;

    /**
     * Delete terminal runs older than the retention window. Running rows are
     * never deleted — closeStaleRuns() closes them first.
     *
     * @param int $retentionDays Age in days beyond which terminal runs go
     *
     * @return int How many runs were deleted.
     */
    public function pruneRuns(int $retentionDays): int;

    /**
     * @param int $id The run row id
     *
     * @return RunRecord|null The run, null when absent.
     */
    public function run(int $id): RunRecord|null;

    /**
     * @param string|null $scheduleId Filter to one schedule, null for all
     * @param int $limit Maximum rows returned
     *
     * @return list<RunRecord> Newest runs first.
     */
    public function runs(string|null $scheduleId, int $limit): array;
}
