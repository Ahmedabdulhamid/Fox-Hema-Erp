<?php

namespace Tests\Unit;

use DOMDocument;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class IsolatedDatabaseBootstrapTest extends TestCase
{
    private function bootstrapPath(): string
    {
        $configuration = new DOMDocument;
        $configuration->load(dirname(__DIR__, 2).'/phpunit.xml');
        $bootstrap = $configuration->documentElement->getAttribute('bootstrap');
        $this->assertSame('tests/Support/TreasuryDatabaseBootstrap.php', $bootstrap);

        return $bootstrap;
    }

    /** @param array<string, string> $environment */
    private function probe(string $code, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, '-r', $code], dirname(__DIR__, 2), array_replace([
            'TREASURY_TEST_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'TREASURY_TEST_PORT' => getenv('DB_PORT') ?: '33316',
            'TREASURY_TEST_USER' => getenv('DB_USERNAME') ?: 'root',
            'TREASURY_TEST_PASSWORD' => getenv('DB_PASSWORD') ?: '',
            'APP_ENV' => 'production', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'development_do_not_use',
            'DB_HOST' => '192.0.2.1', 'DB_PORT' => '1', 'DB_USERNAME' => 'development_do_not_use',
            'DB_PASSWORD' => 'development_do_not_use', 'DB_URL' => 'mysql://invalid:invalid@192.0.2.1/development_do_not_use',
            'DB_SOCKET' => '/development_do_not_use.sock',
            'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/bootstrap/cache/config.php',
        ], $environment));
        $process->setTimeout(20)->run();

        return $process;
    }

    public function test_default_bootstrap_overrides_inherited_database_settings_and_drops_each_unique_schema(): void
    {
        $bootstrap = $this->bootstrapPath();
        $code = 'require '.var_export($bootstrap, true).';'.<<<'PHP'
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'database' => config('database.connections.mysql.database'),
            'connected_database' => Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db,
            'driver' => Illuminate\Support\Facades\DB::getDriverName(),
            'environment' => $app->environment(), 'url' => config('database.connections.mysql.url'),
            'socket' => config('database.connections.mysql.unix_socket'),
            'config_cached' => $app->configurationIsCached(), 'config_cache' => $app->getCachedConfigPath(),
        ], JSON_THROW_ON_ERROR);
        PHP;
        $databases = [];
        $pdo = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
        for ($index = 0; $index < 2; $index++) {
            $process = $this->probe($code);
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $output = $process->getOutput();
            $result = json_decode(substr($output, strpos($output, '{')), true, flags: JSON_THROW_ON_ERROR);
            $database = $result['database'];
            $this->assertMatchesRegularExpression('/^codex_public_ids_test_treasury_[0-9]+_[a-f0-9]{8}$/D', $database);
            $this->assertSame($database, $result['connected_database']);
            $this->assertSame('mysql', $result['driver']);
            $this->assertSame('testing', $result['environment']);
            $this->assertSame('', $result['url']);
            $this->assertSame('', $result['socket']);
            $this->assertFalse($result['config_cached']);
            $this->assertStringEndsWith('/'.$database.'-config.php', str_replace('\\', '/', $result['config_cache']));
            $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $query->execute([$database]);
            $this->assertSame(0, (int) $query->fetchColumn(), 'The child process must drop only its disposable schema at shutdown.');
            $databases[] = $database;
        }
        $this->assertNotSame($databases[0], $databases[1]);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalidEndpoints(): array
    {
        return [
            'remote host' => [['TREASURY_TEST_HOST' => '192.0.2.1']],
            'DSN injection' => [['TREASURY_TEST_PORT' => '33316;dbname=development_do_not_use']],
            'zero port' => [['TREASURY_TEST_PORT' => '0']],
            'out of range port' => [['TREASURY_TEST_PORT' => '65536']],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('invalidEndpoints')]
    public function test_default_bootstrap_rejects_invalid_endpoints_before_schema_creation(array $environment): void
    {
        $process = $this->probe('require '.var_export($this->bootstrapPath(), true).'; echo "APPLICATION_REACHED";', $environment);
        $output = $process->getErrorOutput().$process->getOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('local isolated database server', $output);
        $this->assertStringNotContainsString('Isolated Treasury test schema:', $output);
        $this->assertStringNotContainsString('APPLICATION_REACHED', $output);
    }

    public function test_unavailable_isolated_server_fails_before_application_boot_without_development_fallback(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $endpoint = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = substr($endpoint, strrpos($endpoint, ':') + 1);
        $process = $this->probe('require '.var_export($this->bootstrapPath(), true).'; echo "APPLICATION_REACHED";', ['TREASURY_TEST_PORT' => $port]);
        $output = $process->getErrorOutput().$process->getOutput();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Disposable test database unavailable', $output);
        $this->assertStringContainsString('No development database fallback', $output);
        $this->assertStringNotContainsString('Isolated Treasury test schema:', $output);
        $this->assertStringNotContainsString('APPLICATION_REACHED', $output);
    }
}
