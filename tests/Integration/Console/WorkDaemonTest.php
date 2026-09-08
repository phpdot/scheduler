<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Console;

use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Store\RelationalStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Throwable;

final class WorkDaemonTest extends TestCase
{
    #[Test]
    public function sigtermStopsTheDaemonInterruptsTheLiveTaskAndRecordsIt(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('ext-pcntl is not available.');
        }

        $database = sys_get_temp_dir() . '/scheduler-work-' . uniqid('', false) . '.sqlite';
        $daemon = new Process([PHP_BINARY, __DIR__ . '/work-fixture.php', $database]);
        $daemon->setTimeout(30);
        $daemon->start();

        $watcherConnection = null;
        $watcher = null;
        $claimed = false;

        try {
            $deadline = microtime(true) + 15;

            while (microtime(true) < $deadline) {
                try {
                    $watcherConnection ??= new DatabaseConnection(new SqliteConfig(database: $database));
                    $watcher ??= new RelationalStore($watcherConnection);

                    if ($watcher->hasLiveRun('long-task')) {
                        $claimed = true;

                        break;
                    }
                } catch (Throwable) {
                    usleep(250000);

                    continue;
                }

                usleep(250000);
            }

            self::assertTrue($claimed, 'the daemon never claimed the live task');

            $daemon->signal(SIGTERM);
            $exit = $daemon->wait();
            $output = $daemon->getOutput() . $daemon->getErrorOutput();

            self::assertSame(0, $exit);
            self::assertStringContainsString('stopped — running tasks were stopped and recorded.', $output);

            $runs = $watcher?->runs('long-task', 5) ?? [];

            self::assertCount(1, $runs);
            self::assertSame(RunState::Failed, $runs[0]->state);
            self::assertSame('Stopped for host shutdown.', $runs[0]->error);
            self::assertNotSame(0, $runs[0]->exitCode);
            self::assertLessThan(30000, $runs[0]->durationMs ?? 0);
        } finally {
            if ($daemon->isRunning()) {
                $daemon->stop(5);
            }

            if ($watcherConnection !== null && $watcherConnection->isConnected()) {
                $watcherConnection->close();
            }

            if (file_exists($database)) {
                unlink($database);
            }
        }
    }
}
