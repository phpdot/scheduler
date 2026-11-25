<?php

declare(strict_types=1);

/**
 * The overlap guard against a genuinely concurrent session: a first session
 * holds a schedule row and an uncommitted live run of it while a second
 * process, on a stricter isolation than the server's default, claims another
 * tick of the same schedule.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Tests\Support;

use PHPdot\Database\DatabaseConnection;
use Symfony\Component\Process\Process;

trait RacesARival
{
    /**
     * @param string $isolation The session isolation the rival runs under
     * @param int $round What numbers the ticks of this round, so rounds never share one
     *
     * @return string What the rival's claim answered: refused, or claimed.
     */
    private function rivalVerdict(string $isolation, int $round): string
    {
        $this->storedDefinition();
        $this->connection->affectingStatement('DELETE FROM scheduler_runs');
        $holder = $this->createConnection();
        $holder->beginTransaction();
        $holder->selectOne('SELECT id FROM scheduler_schedules WHERE id = ? FOR UPDATE', ['every-minute']);
        $holder->affectingStatement(
            sprintf(
                "INSERT INTO scheduler_runs (schedule_id, tick, state, attempt, claimed_by, server_host, started_at, heartbeat_at, lease_expires_at) VALUES ('every-minute', '2026-09-05T12:34:0%d+00:00', 'running', 1, 'holder:100', 'box-1', '2026-01-01 00:00:00.000000', '2026-01-01 00:00:00.000000', '2099-01-01 00:00:00.000000')",
                $round,
            ),
        );

        $rival = new Process([PHP_BINARY, dirname(__DIR__) . '/Integration/rival-fixture.php', $this->connection->getDriverName(), $isolation, (string) $round]);
        $rival->setTimeout(30);
        $rival->start();

        try {
            $deadline = microtime(true) + 15;

            while (!str_contains($rival->getIncrementalErrorOutput(), 'ready') && microtime(true) < $deadline) {
                usleep(20000);
            }

            usleep(400000);
            $holder->commit();
            $rival->wait();
        } finally {
            $holder->close();
        }

        self::assertTrue($rival->isSuccessful(), $rival->getErrorOutput());
        self::assertStringContainsString('ready', $rival->getErrorOutput(), 'the rival never reached its claim');

        return trim($rival->getOutput());
    }

    abstract protected function createConnection(): DatabaseConnection;
}
