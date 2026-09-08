<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\PostgreSql;

use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Tests\Integration\RelationalStoreTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class RelationalStoreTest extends RelationalStoreTestCase
{
    protected function createConnection(): DatabaseConnection
    {
        return new DatabaseConnection(new PostgresConfig(
            database: getenv('PG_DB') ?: 'phpdot_test',
            host: getenv('PG_HOST') ?: '127.0.0.1',
            port: (int) (getenv('PG_PORT') ?: 5432),
            username: getenv('PG_USER') ?: 'postgres',
            password: getenv('PG_PASS') ?: 'postgres',
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
