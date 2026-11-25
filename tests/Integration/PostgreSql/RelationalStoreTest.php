<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\PostgreSql;

use PHPdot\Database\Connection\Postgres\PostgresConfig;
use PHPdot\Database\DatabaseConnection;
use PHPdot\Scheduler\Tests\Integration\RelationalStoreTestCase;
use PHPdot\Scheduler\Tests\Support\RacesARival;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('integration')]
final class RelationalStoreTest extends RelationalStoreTestCase
{
    use RacesARival;

    protected function createConnection(): DatabaseConnection
    {
        return new DatabaseConnection(new PostgresConfig(
            database: $this->setting('PG_DB', 'phpdot_test'),
            host: $this->setting('PG_HOST', '127.0.0.1'),
            port: (int) $this->setting('PG_PORT', '5432'),
            username: $this->setting('PG_USER', 'postgres'),
            password: $this->setting('PG_PASS', 'postgres'),
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
