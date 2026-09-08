<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\MySql;

use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Tests\Integration\RelationalStoreTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('mysql')]
#[Group('integration')]
final class RelationalStoreTest extends RelationalStoreTestCase
{
    protected function createConnection(): DatabaseConnection
    {
        return new DatabaseConnection(new MySqlConfig(
            host: getenv('MYSQL_HOST') ?: '127.0.0.1',
            port: (int) (getenv('MYSQL_PORT') ?: 3306),
            database: getenv('MYSQL_DB') ?: 'phpdot_test',
            username: getenv('MYSQL_USER') ?: 'root',
            password: getenv('MYSQL_PASS') ?: 'root',
        ));
    }

    protected function skipOrFail(string $reason): never
    {
        if (getenv('DB_TESTS_REQUIRED') === '1') {
            self::fail($reason . ' (DB_TESTS_REQUIRED=1 — integration coverage may not be skipped)');
        }

        self::markTestSkipped($reason);
    }
}
