<?php

declare(strict_types=1);

/**
 * Boots scheduler:work over a throwaway sqlite database with one live sleep
 * task — the daemon the WorkDaemonTest signals and inspects.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Cli\WorkCommand;
use PHPdot\Scheduler\Config\SchedulerConfig;
use PHPdot\Scheduler\Engine\Scheduler;
use PHPdot\Scheduler\Runner\ProcessRunner;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use PHPdot\Scheduler\Store\RelationalStore;
use PHPdot\Scheduler\Store\SchedulerSchema;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$database = $argv[1] ?? '';

if (!is_string($database) || $database === '') {
    fwrite(STDERR, "usage: php work-fixture.php <database>\n");
    exit(2);
}

$connection = new DatabaseConnection(new SqliteConfig(database: $database));
(new SchedulerSchema($connection))->install();

$store = new RelationalStore($connection);
$store->saveDefinition(new ScheduleDefinition(
    id: 'long-task',
    command: 'sleep 30',
    pattern: Pattern::every('1h'),
    graceSeconds: 3700,
    timeoutSeconds: 60,
));

$config = new SchedulerConfig(serverHost: 'work-test-host');
$engine = new Scheduler($store, new ProcessRunner(), $config);

$app = new Application('scheduler work fixture', '0.1.0');
$app->setAutoExit(false);
$app->addCommands([new WorkCommand($engine, $config)]);

exit($app->run(new StringInput('scheduler:work --interval=5'), new ConsoleOutput()));
