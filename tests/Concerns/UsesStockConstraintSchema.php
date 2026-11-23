<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use Throwable;

trait UsesStockConstraintSchema
{
    private const STOCK_CONSTRAINT_MIGRATION = 'app/Modules/Inventory/Database/Migrations/2026_10_31_000001_add_stock_balance_arithmetic_constraints.php';

    private string $stockConstraintDatabase;

    public function createApplication(): Application
    {
        $host = getenv('STOCK_CONSTRAINT_TEST_HOST');
        $port = getenv('STOCK_CONSTRAINT_TEST_PORT');
        $engine = getenv('STOCK_CONSTRAINT_TEST_ENGINE');
        $expectedIdentity = getenv('STOCK_CONSTRAINT_TEST_ID');
        if (getenv('APP_ENV') !== 'testing' || $host !== '127.0.0.1' || ! in_array($port, ['33316', '33317'], true)
            || ! in_array($engine, ['mysql', 'mariadb'], true) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $expectedIdentity)) {
            throw new LogicException('Stock constraint tests require an independently verified disposable server identity.');
        }
        $server = new PDO('mysql:host='.$host.';port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3, PDO::ATTR_STRINGIFY_FETCHES => true]);
        $identity = $server->query('SELECT VERSION() AS version, @@hostname AS hostname, @@port AS port, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
        if (hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) !== $expectedIdentity
            || str_contains(strtolower($identity['version']), 'mariadb') !== ($engine === 'mariadb')) {
            throw new LogicException('Disposable server differs from the independently verified identity.');
        }
        $purpose = str_contains(static::class, 'Legacy') ? 'legacy' : 'migration';
        $database = 'codex_public_ids_test_phase1c_'.$purpose.'_'.bin2hex(random_bytes(6));
        $this->stockConstraintDatabase = $database;
        if ((int) $server->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}'")->fetchColumn() !== 0) {
            throw new LogicException('Disposable schema must be newly generated.');
        }
        $server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            $root = dirname(__DIR__, 2);
            if (is_file($root.'/.env.isolated-phase1c-tests')) {
                throw new LogicException('Isolated tests must not load an existing dotenv file.');
            }
            foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
                'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_SOCKET' => '',
                'APP_CONFIG_CACHE' => $root.'/storage/framework/'.$database.'-config.php',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
                putenv($key.'='.$value);
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
            if (is_file(getenv('APP_CONFIG_CACHE'))) {
                throw new LogicException('Disposable tests must not load cached configuration.');
            }
            $app = require $root.'/bootstrap/app.php';
            $app->loadEnvironmentFrom('.env.isolated-phase1c-tests');
            $this->traitsUsedByTest = class_uses_recursive(static::class);
            $app->make(Kernel::class)->bootstrap();
            $connection = $app->make('db')->connection();
            if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
                || $connection->getConfig('host') !== $host || (string) $connection->getConfig('port') !== $port
                || $connection->selectOne('SELECT DATABASE() AS db')->db !== $database
                || (int) $server->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$database}'")->fetchColumn() !== 0) {
                throw new LogicException('Resolved connection is not the new empty disposable schema.');
            }
            $this->beforeApplicationDestroyed(function () use ($app, $server, $database): void {
                $app->make('db')->disconnect();
                $server->exec('DROP DATABASE '.$database);
            });

            return $app;
        } catch (Throwable $exception) {
            $server->exec('DROP DATABASE '.$database);
            throw $exception;
        }
    }

    protected function stockBalanceMigration(): Migration
    {
        return require base_path(self::STOCK_CONSTRAINT_MIGRATION);
    }

    protected function assertStockSchemaIdentity(): void
    {
        $this->assertSame($this->stockConstraintDatabase, DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->assertMatchesRegularExpression('/^codex_public_ids_test_phase1c_(legacy|migration)_[a-f0-9]{12}$/D', $this->stockConstraintDatabase);
    }

    /** @param list<string> $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function stockTableEvidence(array $tables): array
    {
        $evidence = [];
        foreach ($tables as $table) {
            $evidence[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $evidence;
    }
}
