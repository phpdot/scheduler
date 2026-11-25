<?php

declare(strict_types=1);

/**
 * A second session against the test database, put on a stricter isolation
 * than the server's default, that claims another tick of a schedule whose
 * row a first session is holding — the rival the overlap guard must refuse.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Store\RelationalStore;

$autoload = __DIR__;

while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}

require $autoload . '/vendor/autoload.php';

$driver = $argv[1] ?? '';
$level = $argv[2] ?? '';
$round = $argv[3] ?? '';

if (!in_array($driver, ['mysql', 'pgsql'], true) || !in_array($level, ['repeatable read', 'serializable'], true) || !ctype_digit($round)) {
    fwrite(STDERR, "usage: php rival-fixture.php <mysql|pgsql> <repeatable read|serializable> <round>\n");
    exit(2);
}

$setting = static function (string $name, string $fallback): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $fallback;
};

$connection = $driver === 'mysql'
    ? new DatabaseConnection(new MySqlConfig(
        host: $setting('MYSQL_HOST', '127.0.0.1'),
        port: (int) $setting('MYSQL_PORT', '3306'),
        database: $setting('MYSQL_DB', 'phpdot_test'),
        username: $setting('MYSQL_USER', 'root'),
        password: $setting('MYSQL_PASS', 'root'),
    ))
    : new DatabaseConnection(new PostgresConfig(
        database: $setting('PG_DB', 'phpdot_test'),
        host: $setting('PG_HOST', '127.0.0.1'),
        port: (int) $setting('PG_PORT', '5432'),
        username: $setting('PG_USER', 'postgres'),
        password: $setting('PG_PASS', 'postgres'),
    ));

$connection->statement($driver === 'mysql'
    ? sprintf('SET SESSION TRANSACTION ISOLATION LEVEL %s', strtoupper($level))
    : sprintf("SET default_transaction_isolation TO '%s'", $level));

$store = new RelationalStore($connection);
$definition = $store->definition('every-minute');

fwrite(STDERR, "ready\n");

$run = $definition === null ? null : $store->claim($definition, sprintf('2026-09-05T12:35:0%d+00:00', (int) $round), 'rival:200', 'box-2', 60, 5);

echo $run === null ? 'refused' : 'claimed';
