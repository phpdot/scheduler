<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\MySql;

use PHPdot\Database\Connection\MySql\MySqlConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Tests\Integration\RelationalStoreTestCase;
use PHPdot\Scheduler\Tests\Support\RacesARival;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('mysql')]
#[Group('integration')]
final class RelationalStoreTest extends RelationalStoreTestCase
{
    use RacesARival;

    protected function createConnection(): DatabaseConnection
    {
        return new DatabaseConnection(new MySqlConfig(
            host: $this->setting('MYSQL_HOST', '127.0.0.1'),
            port: (int) $this->setting('MYSQL_PORT', '3306'),
            database: $this->setting('MYSQL_DB', 'phpdot_test'),
            username: $this->setting('MYSQL_USER', 'root'),
            password: $this->setting('MYSQL_PASS', 'root'),
        ));
    }

    protected function skipOrFail(string $reason): never
    {
        if (getenv('DB_TESTS_REQUIRED') === '1') {
            self::fail($reason . ' (DB_TESTS_REQUIRED=1 — integration coverage may not be skipped)');
        }

        self::markTestSkipped($reason);
    }

    #[Test]
    public function theOverlapGuardHoldsUnderAStricterIsolation(): void
    {
        self::assertSame('refused', $this->rivalVerdict('repeatable read', 1));
        self::assertSame('refused', $this->rivalVerdict('serializable', 2));
    }
}
