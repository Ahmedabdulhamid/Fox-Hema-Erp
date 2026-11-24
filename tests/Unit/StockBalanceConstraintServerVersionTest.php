<?php

namespace Tests\Unit;

use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class StockBalanceConstraintServerVersionTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function supportedVersions(): array
    {
        return [
            'ubuntu mysql production format' => ['8.0.46-0ubuntu0.24.04.5', false],
            'mysql enforcement boundary' => ['8.0.16', false],
            'mariadb enforcement boundary' => ['10.3.10-MariaDB', true],
            'mariadb compatibility prefix' => ['5.5.5-10.3.10-MariaDB-1:10.3.10+maria~bionic', true],
            'current mariadb package format' => ['10.11.14-MariaDB-0+deb12u2', true],
        ];
    }

    #[DataProvider('supportedVersions')]
    public function test_supported_server_version_strings_are_classified(string $version, bool $maria): void
    {
        $this->assertSame($maria, $this->validateVersion($version));
    }

    /** @return array<string, array{string}> */
    public static function unsupportedVersions(): array
    {
        return [
            'mysql 5.7' => ['5.7.44'],
            'ubuntu mysql before enforcement' => ['8.0.15-0ubuntu0.24.04.5'],
            'mariadb before enforcement' => ['10.3.9-MariaDB'],
            'mariadb compatibility prefix before enforcement' => ['5.5.5-10.3.9-MariaDB'],
            'unparseable mysql' => ['8.0'],
            'unparseable mariadb' => ['MariaDB 10.3.10'],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_unsupported_server_version_strings_are_rejected(string $version): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Requires MySQL 8.0.16+ or MariaDB 10.3.10+');

        $this->validateVersion($version);
    }

    private function validateVersion(string $version): bool
    {
        $migration = $this->migration();
        $method = new ReflectionMethod($migration, 'validateServerVersion');

        return $method->invoke($migration, $version);
    }

    private function migration(): Migration
    {
        return require dirname(__DIR__, 2).'/app/Modules/Inventory/Database/Migrations/2026_10_31_000001_add_stock_balance_arithmetic_constraints.php';
    }
}
