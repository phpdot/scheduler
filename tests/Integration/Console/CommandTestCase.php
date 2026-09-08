<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Console;

use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Cli\DefineCommand;
use PHPdot\Scheduler\Cli\InstallCommand;
use PHPdot\Scheduler\Cli\ListCommand;
use PHPdot\Scheduler\Cli\MonitorCommand;
use PHPdot\Scheduler\Cli\PauseCommand;
use PHPdot\Scheduler\Cli\PruneCommand;
use PHPdot\Scheduler\Cli\RemoveCommand;
use PHPdot\Scheduler\Cli\ResumeCommand;
use PHPdot\Scheduler\Cli\RunsCommand;
use PHPdot\Scheduler\Cli\SyncCommand;
use PHPdot\Scheduler\Cli\TickCommand;
use PHPdot\Scheduler\Cli\TriggerCommand;
use PHPdot\Scheduler\Config\SchedulerConfig;
use PHPdot\Scheduler\Engine\Scheduler;
use PHPdot\Scheduler\Runner\ProcessRunner;
use PHPdot\Scheduler\Store\RelationalStore;
use PHPdot\Scheduler\Store\SchedulerSchema;
use PHPdot\Scheduler\Tests\Support\NamedSeededCommand;
use PHPdot\Scheduler\Tests\Support\SeededEchoCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drives the real command classes through a real console application over a
 * throwaway sqlite store — the wiring layer the domain suites never touch.
 */
abstract class CommandTestCase extends TestCase
{
    protected DatabaseConnection $connection;

    protected RelationalStore $store;

    protected Application $app;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new SqliteConfig(database: ':memory:'));
        (new SchedulerSchema($this->connection))->install();
        $this->store = new RelationalStore($this->connection);
        $engine = new Scheduler($this->store, new ProcessRunner(), new SchedulerConfig(serverHost: 'test-host'));

        $this->app = new Application('scheduler test', '0.1.0');
        $this->app->setAutoExit(false);
        $this->app->addCommands([
            new InstallCommand(new SchedulerSchema($this->connection)),
            new DefineCommand($this->store),
            new ListCommand($this->store),
            new TickCommand($engine),
            new RunsCommand($this->store),
            new MonitorCommand($this->store),
            new TriggerCommand($engine),
            new PauseCommand($this->store),
            new ResumeCommand($this->store),
            new RemoveCommand($this->store),
            new PruneCommand($this->store),
            new SyncCommand($this->store),
            new SeededEchoCommand(),
            new NamedSeededCommand(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * @param string $commandLine The full command line, parsed like a shell
     *
     * @return array{int, string} The exit code and the rendered output.
     */
    protected function runCommand(string $commandLine): array
    {
        $output = new BufferedOutput();
        $exit = $this->app->run(new StringInput($commandLine), $output);

        return [$exit, $output->fetch()];
    }
}
