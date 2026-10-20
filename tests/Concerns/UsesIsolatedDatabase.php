<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait UsesIsolatedDatabase
{
    public function setUpUsesIsolatedDatabase(): void
    {
        $connection = config('database.default');
        $database = DB::connection($connection)->getDatabaseName();
        $isolatedMysql = DB::connection($connection)->getDriverName() === 'mysql'
            && str_starts_with($database, 'codex_public_ids_test_');
        if (! $this->app->environment('testing') || ($database !== ':memory:' && ! $isolatedMysql)) {
            throw new LogicException('Database tests require an in-memory database or a dedicated codex_public_ids_test_ MySQL database.');
        }
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        if (method_exists($this, 'beforeRefreshingDatabase')) {
            $this->beforeRefreshingDatabase();
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true]);
        if ($isolatedMysql) {
            $this->clearIsolatedMysqlRecords();
        }
        $this->app[Kernel::class]->setArtisan(null);
    }

    public function tearDownUsesIsolatedDatabase(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->clearIsolatedMysqlRecords();
        }
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }

    private function clearIsolatedMysqlRecords(): void
    {
        if (! $this->app->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new LogicException('Refusing to clear records outside a dedicated test database.');
        }
        Schema::withoutForeignKeyConstraints(function (): void {
            $database = DB::connection()->getDatabaseName();
            foreach (Schema::getTableListing($database, false) as $table) {
                if (str_contains($table, '.') || str_contains($table, '`')) {
                    throw new LogicException('Unexpected qualified table name in isolated cleanup.');
                }
                if ($table !== config('database.migrations.table', 'migrations')) {
                    DB::table($table)->delete();
                }
            }
        });
    }
}
