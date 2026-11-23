<?php

namespace Tests\Feature;

use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VendorPaymentMigrationTest extends TestCase
{
    public function test_additive_upgrade_preserves_legacy_rows_and_constraints(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! str_starts_with($database, 'codex_public_ids_test_purchasing_vendor_payments_')
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Migration tests require their dedicated isolated schema.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        $payment = VendorPayment::factory()->create();
        $allocation = VendorPaymentAllocation::factory()->create(['vendor_payment_id' => $payment->id]);
        $paymentBefore = (array) DB::table('vendor_payments')->where('id', $payment->id)->first();
        $allocationBefore = (array) DB::table('vendor_payment_allocations')->where('id', $allocation->id)->first();
        $lifecycle = require app_path('Modules/Purchasing/Database/Migrations/2026_10_24_204339_add_vendor_payment_lifecycle_metadata.php');
        $reference = require app_path('Modules/Payments/Database/Migrations/2026_10_24_204944_add_vendor_payment_method_reference_constraint.php');
        $reference->down();
        $lifecycle->down();
        $this->assertTrue(Schema::hasIndex('vendor_payment_allocations', ['vendor_payment_id', 'vendor_bill_id'], 'unique'));
        $lifecycle->up();
        $reference->up();
        $this->assertEquals($paymentBefore, (array) DB::table('vendor_payments')->where('id', $payment->id)->first());
        $this->assertEquals($allocationBefore, (array) DB::table('vendor_payment_allocations')->where('id', $allocation->id)->first());
        $this->assertFalse(Schema::hasIndex('vendor_payment_allocations', ['vendor_payment_id', 'vendor_bill_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('vendor_payment_allocations', ['operation_key'], 'unique'));
        $this->assertCount(2, collect(Schema::getForeignKeys('vendor_payment_allocations'))->whereIn('name', ['fk_allocation_payment_supplier', 'fk_allocation_bill_supplier']));
        $this->assertTrue(collect(Schema::getForeignKeys('vendor_payments'))->contains(fn (array $key): bool => $key['foreign_table'] === 'payment_methods'));
        DB::table('vendor_payments')->where('id', $payment->id)->update(['idempotency_key' => (string) Str::uuid7()]);
        try {
            $reference->down();
            $this->fail('Rollback removed governed method references from financial history.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('financial history', $exception->getMessage());
        }
        try {
            $lifecycle->down();
            $this->fail('Rollback discarded financial lifecycle evidence.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('audit evidence', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('vendor_payments', 'idempotency_key'));
        DB::table('vendor_payment_allocations')->where('id', $allocation->id)->delete();
        DB::table('vendor_payments')->where('id', $payment->id)->delete();
        DB::table('vendor_bills')->where('id', $allocation->vendor_bill_id)->delete();
    }
}
