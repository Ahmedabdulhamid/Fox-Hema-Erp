<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Services\ModuleDiscovery;
use App\Modules\Payments\Models\Payment;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\Purchasing\Models\VendorPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TreasuryMigrationTest extends TestCase
{
    public function test_additive_install_preserves_legacy_financial_records_without_adopting_them(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Migration tests require their dedicated isolated Treasury schema.');
        }
        $paths = array_values(array_filter(app(ModuleDiscovery::class)->migrationPaths(),
            fn (string $path): bool => ! str_contains(str_replace('\\', '/', $path), '/Treasury/')));
        $paths[] = database_path('migrations');
        $paths[] = base_path('tests/Fixtures/Migrations');
        $this->artisan('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('treasury_accounts'));
        $payment = Payment::factory()->create();
        $vendor = VendorPayment::factory()->create();
        $drawer = PosCashMovement::factory()->create();
        $before = [];
        foreach (['payments' => $payment, 'vendor_payments' => $vendor, 'pos_cash_movements' => $drawer] as $table => $model) {
            $before[$table] = (array) DB::table($table)->where('id', $model->id)->first();
        }
        $this->artisan('migrate', [
            '--path' => [app_path('Modules/Treasury/Database/Migrations')], '--realpath' => true,
            '--force' => true, '--no-interaction' => true,
        ])->assertExitCode(0);
        foreach ($before as $table => $attributes) {
            $after = (array) DB::table($table)->where('id', $attributes['id'])->first();
            $this->assertSame($attributes, array_intersect_key($after, $attributes));
            if ($table === 'vendor_payments') {
                $this->assertNull($after['treasury_account_id']);
                $this->assertNull($after['recovery_reference']);
            }
        }
        foreach (['treasury_accounts', 'treasury_operations', 'treasury_entries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertTrue(Schema::hasIndex('treasury_operations', ['idempotency_key'], 'unique'));
        $this->assertTrue(Schema::hasIndex('treasury_operations', ['payment_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('treasury_operations', ['vendor_payment_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('treasury_operations', ['reversal_of_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('treasury_operations', ['opening_account_id'], 'unique'));
        $migration = require app_path('Modules/Treasury/Database/Migrations/2026_10_25_020227_create_treasury_foundation_tables.php');
        try {
            $migration->down();
            $this->fail('Financial evidence rollback was accepted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('non-destructive', $exception->getMessage());
        }
        Schema::withoutForeignKeyConstraints(function () use ($before): void {
            foreach ($before as $table => $attributes) {
                DB::table($table)->where('id', $attributes['id'])->delete();
            }
        });
    }
}
