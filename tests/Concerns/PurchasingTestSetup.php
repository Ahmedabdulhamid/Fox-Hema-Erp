<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait PurchasingTestSetup
{
    /** Load real Phase 2 migrations without unrelated MySQL-only payment/matching constraints. */
    protected function setUpPurchasingDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $sqlite = $connection->getDriverName() === 'sqlite' && $database === ':memory:';
        $mysql = $connection->getDriverName() === 'mysql' && str_starts_with($database, 'codex_public_ids_test_purchasing_')
            && $connection->selectOne('SELECT DATABASE() AS db')->db === $database;
        if (! app()->environment('testing') || (! $sqlite && ! $mysql)) {
            throw new LogicException('Purchasing tests require SQLite :memory: or a verified isolated purchasing MySQL schema.');
        }
        $paths = [
            'app/Modules/AccessManagement/Database/Migrations',
            'app/Modules/Products/Database/Migrations',
            'app/Modules/Inventory/Database/Migrations',
            'app/Modules/InventoryCosting/Database/Migrations',
            'app/Modules/Discounts/Database/Migrations',
            'app/Modules/Taxes/Database/Migrations',
        ];
        foreach (glob(app_path('Modules/Purchasing/Database/Migrations/*.php')) as $file) {
            if (preg_match('/create_vendor_payment_allocations|create_vendor_bill_receipt_matches|add_lookup_indexes/', $file)) {
                continue;
            }
            $paths[] = 'app/Modules/Purchasing/Database/Migrations/'.basename($file);
        }
        $options = $mysql ? ['--force' => true] : ['--path' => $paths, '--force' => true];
        $this->artisan('migrate', $options)->assertExitCode(0);
        if ($mysql) {
            Schema::withoutForeignKeyConstraints(function () use ($database): void {
                foreach (Schema::getTableListing($database, false) as $table) {
                    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                        throw new LogicException('Unexpected isolated purchasing table identifier.');
                    }
                    if ($table !== 'migrations') {
                        DB::table($table)->delete();
                    }
                }
            });
        }
    }
}
