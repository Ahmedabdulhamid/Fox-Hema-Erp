<?php

namespace App\Modules\AccessManagement\Services;

use Illuminate\Database\Migrations\Migrator;
use LogicException;

class ModuleMigrator extends Migrator
{
    /** Prerequisite modules must finish before their dependent modules start. */
    private const ORDER = [
        'AccessManagement', 'Products', 'Inventory', 'InventoryCosting', 'Purchasing',
        'Discounts', 'Taxes', 'Sales', 'POS', 'StockTransfer', 'Payments', 'Treasury',
    ];

    public function getMigrationFiles($paths): array
    {
        $seen = [];
        foreach ((array) $paths as $path) {
            foreach (str_ends_with($path, '.php') ? [$path] : $this->files->glob($path.'/*_*.php') as $file) {
                $name = $this->getMigrationName($file);
                $resolved = realpath($file);
                if (isset($seen[$name]) && $seen[$name] !== $resolved) {
                    throw new LogicException("Duplicate migration name: {$name}");
                }
                $seen[$name] = $resolved;
            }
        }
        $files = parent::getMigrationFiles($paths);
        uasort($files, fn (string $left, string $right): int => $this->order($left) <=> $this->order($right));

        return $files;
    }

    protected function rollbackMigrations(array $migrations, $paths, array $options): array
    {
        $files = $this->getMigrationFiles($paths);
        usort($migrations, function ($left, $right) use ($files): int {
            $left = (object) $left;
            $right = (object) $right;

            return (($right->batch ?? 0) <=> ($left->batch ?? 0))
                ?: ($this->order($files[$right->migration] ?? $right->migration) <=> $this->order($files[$left->migration] ?? $left->migration));
        });

        return parent::rollbackMigrations($migrations, $paths, $options);
    }

    /** @return array{int, int, string} */
    private function order(string $file): array
    {
        $path = str_replace('\\', '/', realpath($file) ?: $file);
        $name = $this->getMigrationName($file);
        $phase = str_contains($name, '_add_public_identifiers_') ? 1 : 0;
        foreach (self::ORDER as $priority => $module) {
            if (str_contains($path, "/Modules/{$module}/Database/Migrations/")) {
                return [$phase, $priority, $name];
            }
        }

        return [$phase, count(self::ORDER), $name];
    }
}
