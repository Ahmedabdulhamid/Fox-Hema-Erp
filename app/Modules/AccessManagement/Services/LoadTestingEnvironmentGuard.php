<?php

namespace App\Modules\AccessManagement\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;

class LoadTestingEnvironmentGuard
{
    private const EXPECTED_DATABASE = 'pos_load_test';

    private const EXPECTED_HOST = '127.0.0.1';

    private const EXPECTED_PORT = '3307';

    private const EXPECTED_RUNTIME = 'D:\\PointSale\\load-testing-runtime';

    public function __construct(
        private readonly Application $application,
        private readonly DatabaseManager $database,
    ) {}

    public function assertSafe(): void
    {
        if ($this->application->environment() !== 'load-testing') {
            throw new LogicException('APP_ENV must be exactly load-testing.');
        }

        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new LogicException('The active database driver must be mysql.');
        }
        if ($connection->getDatabaseName() !== self::EXPECTED_DATABASE) {
            throw new LogicException('The active database must be exactly '.self::EXPECTED_DATABASE.'.');
        }
        if ((string) $connection->getConfig('host') !== self::EXPECTED_HOST) {
            throw new LogicException('The active database host must be exactly '.self::EXPECTED_HOST.'.');
        }
        if ((string) $connection->getConfig('port') !== self::EXPECTED_PORT) {
            throw new LogicException('The active database port must be exactly '.self::EXPECTED_PORT.'.');
        }

        $expectedCache = $this->application->basePath('bootstrap/cache/load-testing/config.php');
        if (! $this->application->configurationIsCached()
            || ! is_file($expectedCache)
            || ! $this->samePath($this->application->getCachedConfigPath(), $expectedCache)) {
            throw new LogicException('The isolated load-testing configuration cache must be active.');
        }

        if (! is_dir(self::EXPECTED_RUNTIME)
            || ! $this->samePath($this->application->storagePath(), self::EXPECTED_RUNTIME)) {
            throw new LogicException('The isolated load-testing runtime path must be active.');
        }
    }

    private function samePath(string $actual, string $expected): bool
    {
        $actual = realpath($actual);
        $expected = realpath($expected);

        return is_string($actual) && is_string($expected)
            && strcasecmp(str_replace('\\', '/', $actual), str_replace('\\', '/', $expected)) === 0;
    }
}
