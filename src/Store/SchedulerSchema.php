<?php

declare(strict_types=1);

/**
 * Owns the three scheduler tables: creates, drops, and versions them, so a
 * mixed-version fleet fails loudly instead of corrupting claims.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Store;

use PHPdot\Database\DatabaseConnection;
use PHPdot\Database\Schema\Blueprint;
use PHPdot\Scheduler\Exception\SchemaMismatchException;

final class SchedulerSchema
{
    public const int VERSION = 2;

    public const string SCHEDULES_TABLE = 'scheduler_schedules';

    public const string RUNS_TABLE = 'scheduler_runs';

    public const string CATALOG_TABLE = 'scheduler_catalog';

    public const string META_TABLE = 'scheduler_meta';

    private const string VERSION_KEY = 'schema_version';

    /**
     * @param DatabaseConnection $connection The store's connection
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
    ) {}

    /**
     * @return void
     */
    public function install(): void
    {
        $schema = $this->connection->schema();

        if (!$schema->hasTable(self::SCHEDULES_TABLE)) {
            $schema->create(self::SCHEDULES_TABLE, static function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->text('command');
                $table->string('pattern', 64);
                $table->string('timezone', 64)->default('UTC');
                $table->integer('timeout_seconds')->default(300);
                $table->integer('idle_timeout_seconds')->nullable();
                $table->string('overlap', 16)->default('forbid');
                $table->integer('grace_seconds')->default(90);
                $table->boolean('enabled');
                $table->string('description', 255)->nullable();
                $table->string('created_at', 32)->nullable();
                $table->string('updated_at', 32)->nullable();
            });
        }

        if (!$schema->hasTable(self::RUNS_TABLE)) {
            $schema->create(self::RUNS_TABLE, static function (Blueprint $table): void {
                $table->id();
                $table->string('schedule_id', 64);
                $table->string('tick', 32);
                $table->string('state', 16)->default('running');
                $table->integer('attempt')->default(1);
                $table->string('claimed_by', 128);
                $table->string('server_host', 128);
                $table->string('started_at', 32);
                $table->string('heartbeat_at', 32)->nullable();
                $table->string('lease_expires_at', 32);
                $table->string('finished_at', 32)->nullable();
                $table->integer('exit_code')->nullable();
                $table->integer('duration_ms')->nullable();
                $table->text('output_tail')->nullable();
                $table->text('error')->nullable();
                $table->unique(['schedule_id', 'tick'], 'scheduler_runs_schedule_tick_unique');
                $table->index(['schedule_id'], 'scheduler_runs_schedule_idx');
                $table->index(['server_host', 'state', 'lease_expires_at'], 'scheduler_runs_host_state_lease_idx');
            });
        }

        if (!$schema->hasTable(self::CATALOG_TABLE)) {
            $schema->create(self::CATALOG_TABLE, static function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->text('command');
                $table->string('description', 255)->nullable();
                $table->string('updated_at', 32)->nullable();
            });
        }

        if (!$schema->hasTable(self::META_TABLE)) {
            $schema->create(self::META_TABLE, static function (Blueprint $table): void {
                $table->string('meta_key', 64)->primary();
                $table->string('meta_value', 255);
            });
        }

        $version = $this->version();

        if ($version === null) {
            $this->connection->affectingStatement(
                sprintf('INSERT INTO %s (meta_key, meta_value) VALUES (?, ?)', self::META_TABLE),
                [self::VERSION_KEY, (string) self::VERSION],
            );

            return;
        }

        if ($version !== self::VERSION) {
            $this->connection->affectingStatement(
                sprintf('UPDATE %s SET meta_value = ? WHERE meta_key = ?', self::META_TABLE),
                [(string) self::VERSION, self::VERSION_KEY],
            );
        }
    }

    /**
     * @return void
     */
    public function drop(): void
    {
        $schema = $this->connection->schema();
        $schema->dropIfExists(self::RUNS_TABLE);
        $schema->dropIfExists(self::CATALOG_TABLE);
        $schema->dropIfExists(self::SCHEDULES_TABLE);
        $schema->dropIfExists(self::META_TABLE);
    }

    /**
     * @return int|null The stored schema version, null when no schema exists.
     */
    public function version(): int|null
    {
        if (!$this->connection->schema()->hasTable(self::META_TABLE)) {
            return null;
        }

        $row = $this->connection->selectOne(
            sprintf('SELECT meta_value FROM %s WHERE meta_key = ?', self::META_TABLE),
            [self::VERSION_KEY],
        );

        return $row === null ? null : Columns::int($row, 'meta_value');
    }

    /**
     * @throws SchemaMismatchException When the schema is absent or from another version.
     *
     * @return void
     */
    public function assertInstalled(): void
    {
        $version = $this->version();

        if ($version !== self::VERSION) {
            throw SchemaMismatchException::found($version, self::VERSION);
        }
    }
}
