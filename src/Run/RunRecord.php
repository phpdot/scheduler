<?php

declare(strict_types=1);

/**
 * One row of run history: the claim, its holder, and how it ended.
 *
 * Timestamps are opaque UTC strings minted by the store's own clock in the
 * shape 'Y-m-d H:i:s.u'; the id plus attempt form the fencing token.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Run;

final readonly class RunRecord
{
    /**
     * @param int $id The run row id
     * @param string $scheduleId The schedule this run claims a fire of
     * @param string $tick The tick identifier this run claims
     * @param RunState $state The run's current state
     * @param int $attempt How many runners have claimed this tick; fences zombies
     * @param string $claimedBy The winning server's identity
     * @param string $serverHost The winning server's host, for concurrency caps
     * @param string $startedAt Store-clock UTC timestamp
     * @param string|null $heartbeatAt Store-clock UTC timestamp of the last lease renewal
     * @param string $leaseExpiresAt Store-clock UTC timestamp the claim dies at
     * @param string|null $finishedAt Store-clock UTC timestamp of the recorded end
     * @param int|null $exitCode The command's exit code
     * @param int|null $durationMs How long the command ran
     * @param string|null $outputTail The tail of the command's combined output
     * @param string|null $error Why the run failed, when it did
     */
    public function __construct(
        public int $id,
        public string $scheduleId,
        public string $tick,
        public RunState $state,
        public int $attempt,
        public string $claimedBy,
        public string $serverHost,
        public string $startedAt,
        public string|null $heartbeatAt,
        public string $leaseExpiresAt,
        public string|null $finishedAt,
        public int|null $exitCode,
        public int|null $durationMs,
        public string|null $outputTail,
        public string|null $error,
    ) {}
}
