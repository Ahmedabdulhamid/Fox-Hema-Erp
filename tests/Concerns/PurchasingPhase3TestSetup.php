<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

trait PurchasingPhase3TestSetup
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('app.key', 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');

        return $app;
    }

    protected function setUpPhase3Database(bool $includeBillMatches = false): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || ! ((DB::getDriverName() === 'mysql'
            && preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', $database))
            || (DB::getDriverName() === 'sqlite' && $database === ':memory:'))) {
            throw new LogicException('Phase 3 tests refuse application databases.');
        }
        $paths = ['app/Modules/AccessManagement/Database/Migrations', 'app/Modules/Products/Database/Migrations',
            'app/Modules/Inventory/Database/Migrations', 'app/Modules/InventoryCosting/Database/Migrations',
            'app/Modules/Discounts/Database/Migrations', 'app/Modules/Taxes/Database/Migrations'];
        foreach (glob(app_path('Modules/Purchasing/Database/Migrations/*.php')) as $file) {
            if (! preg_match('/create_vendor_payment_allocations|add_lookup_indexes/', $file)
                && (($includeBillMatches && DB::getDriverName() !== 'sqlite') || ! str_contains($file, 'create_vendor_bill_receipt_matches'))) {
                $paths[] = 'app/Modules/Purchasing/Database/Migrations/'.basename($file);
            }
        }
        if (DB::getDriverName() === 'mysql') {
            if (DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
                throw new LogicException('Unexpected isolated purchasing connection.');
            }
        }
        $options = DB::getDriverName() === 'mysql' ? ['--force' => true] : ['--path' => $paths, '--force' => true];
        $this->artisan('migrate', $options)->assertExitCode(0);
        if ($includeBillMatches && DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE TABLE vendor_bill_receipt_matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(36) NOT NULL UNIQUE,
                vendor_bill_item_id INTEGER NOT NULL REFERENCES vendor_bill_items(id) ON DELETE RESTRICT,
                purchase_receipt_item_id INTEGER NOT NULL REFERENCES purchase_receipt_items(id) ON DELETE RESTRICT,
                matched_quantity NUMERIC NOT NULL CONSTRAINT chk_bill_receipt_match_positive CHECK (matched_quantity > 0),
                created_at DATETIME, updated_at DATETIME,
                CONSTRAINT uq_bill_receipt_match UNIQUE (vendor_bill_item_id, purchase_receipt_item_id)
            )');
        }
        if (DB::getDriverName() === 'mysql') {
            Schema::withoutForeignKeyConstraints(function (): void {
                foreach (Schema::getTableListing($this->app['db']->connection()->getDatabaseName(), false) as $table) {
                    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                        throw new LogicException('Unexpected test table identifier.');
                    }
                    if ($table !== 'migrations') {
                        DB::table($table)->delete();
                    }
                }
            });
        }
    }
}
