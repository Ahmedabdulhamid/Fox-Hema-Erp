<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase as FrameworkRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;

trait RefreshDatabase
{
    use FrameworkRefreshDatabase;

    private static bool $hasInitializedSchema = false;

    public function refreshDatabase(): void
    {
        foreach ($this->connectionsToTransact() as $connection) {
            $this->assertIsolatedTestDatabase($connection);
        }
        $this->beforeRefreshingDatabase();

        if (! self::$hasInitializedSchema) {
            foreach ($this->connectionsToTransact() as $connection) {
                $this->assertIsolatedTestDatabase($connection);
                DB::purge($connection);
            }
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
            self::$hasInitializedSchema = true;
        }

        $this->restoreInMemoryDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
    }

    protected function migrateDatabases(): void
    {
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        foreach ($this->connectionsToTransact() as $connection) {
            $this->assertIsolatedTestDatabase($connection);
        }

        $this->artisan('migrate', ['--force' => true]);
    }

    private function assertIsolatedTestDatabase(?string $connection): void
    {
        $connection ??= config('database.default');
        $resolved = DB::connection($connection);
        $database = $resolved->getDatabaseName();
        $isIsolatedMysql = $resolved->getDriverName() === 'mysql'
            && is_string($database) && str_starts_with($database, 'codex_public_ids_test_');

        $isInMemory = $resolved->getDriverName() === 'sqlite' && $database === ':memory:';
        if (! $this->app->environment('testing') || (! $isInMemory && ! $isIsolatedMysql)) {
            throw new LogicException('Database tests require an in-memory database or a dedicated codex_public_ids_test_ MySQL database.');
        }
    }
}
