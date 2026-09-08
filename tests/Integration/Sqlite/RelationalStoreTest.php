<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Sqlite;

use PHPdot\Database\Connection\Sqlite\SqliteConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Tests\Integration\RelationalStoreTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class RelationalStoreTest extends RelationalStoreTestCase
{
    protected function createConnection(): DatabaseConnection
    {
        return new DatabaseConnection(new SqliteConfig(database: ':memory:'));
    }

    protected function skipOrFail(string $reason): never
    {
        self::markTestSkipped($reason);
    }
}
