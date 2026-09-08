<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Support;

use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Run\RunOutcome;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use RuntimeException;

/**
 * An in-memory store with the claim semantics the engine tests need: a tick
 * claimed once stays claimed, live runs block Forbid schedules, and finishes
 * can be scripted to fence.
 */
final class FakeStore implements SchedulerStoreInterface
{
    /** @var array<string, ScheduleDefinition> */
    public array $definitions = [];

    /** @var array<string, RunRecord> Keys are "schedule|tick". */
    public array $runs = [];

    /** @var list<string> Schedule ids scripted to refuse a claim as taken. */
    public array $takenTicks = [];

    public int $liveRunsForHost = 0;

    public bool $refuseFinish = false;

    public int $heartbeats = 0;

    public bool $heartbeatsThrow = false;

    public function enabledDefinitions(): array
    {
        return array_values(array_filter($this->definitions, static fn(ScheduleDefinition $definition): bool => $definition->enabled));
    }

    public function allDefinitions(): array
    {
        return array_values($this->definitions);
    }

    /** @var array<string, CatalogEntry> */
    public array $catalog = [];

    public function catalogEntries(): array
    {
        return array_values($this->catalog);
    }

    public function reconcileCatalog(array $entries): array
    {
        $added = 0;
        $updated = 0;
        $removed = 0;
        $incoming = [];

        foreach ($entries as $entry) {
            $incoming[$entry->name] = $entry;
            $current = $this->catalog[$entry->name] ?? null;

            if ($current === null) {
                $added++;
            } elseif ($current->command !== $entry->command || $current->description !== $entry->description) {
                $updated++;
            }

            $this->catalog[$entry->name] = $entry;
        }

        foreach (array_keys($this->catalog) as $name) {
            if (!isset($incoming[$name])) {
                unset($this->catalog[$name]);
                $removed++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    public function definition(string $id): ScheduleDefinition|null
    {
        return $this->definitions[$id] ?? null;
    }

    public function saveDefinition(ScheduleDefinition $definition): void
    {
        $this->definitions[$definition->id] = $definition;
    }

    public function deleteDefinition(string $id): bool
    {
        if (!isset($this->definitions[$id])) {
            return false;
        }

        unset($this->definitions[$id]);

        return true;
    }

    public function claim(
        ScheduleDefinition $definition,
        string $tick,
        string $serverId,
        string $serverHost,
        int $leaseTtlSeconds,
        int $concurrencyLimit,
    ): RunRecord|null {
        $key = $definition->id . '|' . $tick;

        if ($this->liveRunsForHost >= $concurrencyLimit) {
            return null;
        }

        if (isset($this->runs[$key]) || in_array($key, $this->takenTicks, true)) {
            return null;
        }

        $run = new RunRecord(
            id: count($this->runs) + 1,
            scheduleId: $definition->id,
            tick: $tick,
            state: RunState::Running,
            attempt: 1,
            claimedBy: $serverId,
            serverHost: $serverHost,
            startedAt: '2026-01-01 00:00:00.000000',
            heartbeatAt: null,
            leaseExpiresAt: '2026-01-01 00:01:00.000000',
            finishedAt: null,
            exitCode: null,
            durationMs: null,
            outputTail: null,
            error: null,
        );

        $this->runs[$key] = $run;

        return $run;
    }

    public function heartbeat(RunRecord $run, int $leaseTtlSeconds): bool
    {
        $this->heartbeats++;

        if ($this->heartbeatsThrow) {
            throw new RuntimeException('store unreachable');
        }

        return true;
    }

    public function finish(RunRecord $run, RunOutcome $outcome): bool
    {
        if ($this->refuseFinish) {
            return false;
        }

        $key = $run->scheduleId . '|' . $run->tick;
        $this->runs[$key] = new RunRecord(
            $run->id,
            $run->scheduleId,
            $run->tick,
            $outcome->state,
            $run->attempt,
            $run->claimedBy,
            $run->serverHost,
            $run->startedAt,
            $run->heartbeatAt,
            $run->leaseExpiresAt,
            '2026-01-01 00:00:05.000000',
            $outcome->exitCode,
            $outcome->durationMs,
            $outcome->outputTail,
            $outcome->error,
        );

        return true;
    }

    public function hasLiveRun(string $scheduleId): bool
    {
        foreach ($this->runs as $run) {
            if ($run->scheduleId === $scheduleId && $run->state === RunState::Running) {
                return true;
            }
        }

        return false;
    }

    public function liveRunCountForHost(string $serverHost): int
    {
        return $this->liveRunsForHost;
    }

    public function closeStaleRuns(int $graceSeconds): int
    {
        return 0;
    }

    public function pruneRuns(int $retentionDays): int
    {
        return 0;
    }

    public function run(int $id): RunRecord|null
    {
        foreach ($this->runs as $run) {
            if ($run->id === $id) {
                return $run;
            }
        }

        return null;
    }

    public function runs(string|null $scheduleId, int $limit): array
    {
        $runs = array_values($this->runs);

        if ($scheduleId !== null) {
            $runs = array_values(array_filter($runs, static fn(RunRecord $run): bool => $run->scheduleId === $scheduleId));
        }

        return array_slice($runs, 0, $limit);
    }
}
