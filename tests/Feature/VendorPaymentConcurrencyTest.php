<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VendorPaymentConcurrencyTest extends TestCase
{
    public function test_distinct_vendor_disbursements_cannot_overspend_the_same_source(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Concurrency tests require an explicitly isolated database.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Concurrent withdrawals', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($actor, $treasury->createDraft($actor, ['type' => 'opening_balance', 'account_id' => $cash->id,
            'amount' => '150', 'effective_date' => now()->format('Y-m-d'), 'reason' => 'Isolated race fixture'], (string) Str::uuid7()));
        $data = ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $branch->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id, 'treasury_account_id' => $cash->id,
            'payment_date' => now()->format('Y-m-d'), 'amount' => '100'];
        $payments = app(VendorPaymentService::class);
        $first = $payments->createDraft($actor, $data, (string) Str::uuid7());
        $second = $payments->createDraft($actor, $data, (string) Str::uuid7());
        $environment = ['VP_ACTOR' => (string) $actor->id, 'VP_LOCATION' => (string) $branch->id, 'VP_PAYMENT' => (string) $first->id];
        $results = $this->workers($environment, 'post', [[], ['VP_PAYMENT' => (string) $second->id]]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $this->assertSame('50.0000', $treasury->balance($actor, $cash));
        $this->assertSame(1, VendorPayment::whereIn('id', [$first->id, $second->id])->where('status', 'posted')->count());
        $winner = VendorPayment::whereIn('id', [$first->id, $second->id])->where('status', 'posted')->firstOrFail();
        $environment['VP_PAYMENT'] = (string) $winner->id;
        $this->assertSame(['ok', 'ok'], array_column($this->workers($environment, 'post'), 'status'));
        $this->assertSame('50.0000', $treasury->balance($actor, $cash));
        $this->assertSame(1, TreasuryOperation::whereIn('vendor_payment_id', [$first->id, $second->id])->count());
        $environment['VP_KEY'] = (string) Str::uuid7();
        $recovery = $this->workers($environment, 'reverse', [[], ['VP_KEY' => (string) Str::uuid7()]]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($recovery, 'status'));
        $this->assertSame('150.0000', $treasury->balance($actor, $cash));
    }

    public function test_real_connections_serialize_posting_allocations_advances_retries_and_reversals(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Concurrency tests require an explicitly isolated database.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Concurrent vendors', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($actor, $treasury->createDraft($actor, ['type' => 'opening_balance', 'account_id' => $cash->id, 'amount' => '10000', 'effective_date' => '2026-10-24', 'reason' => 'Isolated concurrency funds'], (string) Str::uuid7()));
        $supplier = Supplier::factory()->create();
        $method = PaymentMethod::factory()->create();
        $environment = ['VP_ACTOR' => (string) $actor->id, 'VP_LOCATION' => (string) $branch->id,
            'VP_ACCOUNT' => (string) $cash->id, 'VP_SUPPLIER' => (string) $supplier->id, 'VP_METHOD' => (string) $method->id, 'VP_KEY' => (string) Str::uuid7()];
        $created = $this->workers($environment, 'create');
        $this->assertSame(['ok', 'ok'], array_column($created, 'status'));
        $this->assertSame($created[0]['id'], $created[1]['id']);
        $environment['VP_PAYMENT'] = (string) $created[0]['id'];
        $this->assertSame(['ok', 'ok'], array_column($this->workers($environment, 'post'), 'status'));
        $this->assertSame('9900.0000', $treasury->balance($actor, $cash));
        $firstPayment = VendorPayment::findOrFail($created[0]['id']);
        $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'status' => 'confirmed', 'created_by' => $actor->id]);
        $firstBill = $this->bill($order, $actor);
        $secondBill = $this->bill($order, $actor);
        $payments = app(VendorPaymentService::class);
        $secondPayment = $payments->post($actor, $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => '2026-10-24', 'amount' => '100'], (string) Str::uuid7()));
        $environment['VP_BILL'] = (string) $firstBill->id;
        $environment['VP_KEY'] = (string) Str::uuid7();
        $retry = $this->workers($environment, 'allocate');
        $this->assertSame(['ok', 'ok'], array_column($retry, 'status'));
        $this->assertSame($retry[0]['id'], $retry[1]['id']);
        $environment['VP_KEY'] = (string) Str::uuid7();
        $environment['VP_PAYMENT'] = (string) $secondPayment->id;
        $thirdPayment = $payments->post($actor, $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => '2026-10-24', 'amount' => '100'], (string) Str::uuid7()));
        $raceBill = $this->bill($order, $actor);
        $environment['VP_BILL'] = (string) $raceBill->id;
        $billRace = $this->workers($environment, 'allocate', [[], ['VP_PAYMENT' => (string) $thirdPayment->id, 'VP_KEY' => (string) Str::uuid7()]]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($billRace, 'status'));
        $this->assertSame('100.0000', app(SupplierFinancialBalances::class)->bill($actor, $raceBill)['paid']);
        $advance = $payments->post($actor, $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => '2026-10-24', 'amount' => '100'], (string) Str::uuid7()));
        $environment['VP_PAYMENT'] = (string) $advance->id;
        $environment['VP_KEY'] = (string) Str::uuid7();
        $environment['VP_BILL'] = (string) $secondBill->id;
        $advanceRace = $this->workers($environment, 'allocate', [[], ['VP_BILL' => (string) $this->bill($order, $actor)->id, 'VP_KEY' => (string) Str::uuid7()]]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($advanceRace, 'status'));
        $this->assertSame('0.0000', app(SupplierFinancialBalances::class)->payment($actor, $advance)['unallocated']);
        $environment['VP_PAYMENT'] = (string) $firstPayment->id;
        $environment['VP_KEY'] = (string) Str::uuid7();
        $reversal = $this->workers($environment, 'reverse');
        $this->assertSame(['ok', 'ok'], array_column($reversal, 'status'));
        $this->assertSame('100.0000', app(SupplierFinancialBalances::class)->bill($actor, $firstBill)['outstanding']);
        $environment['VP_KEY'] = (string) Str::uuid7();
        $conflicts = $this->workers($environment, 'conflict');
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($conflicts, 'status'));

        $stalePayment = $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => '2026-10-24', 'amount' => '100'], (string) Str::uuid7());
        $sparePayment = $payments->post($actor, $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => '2026-10-24', 'amount' => '100'], (string) Str::uuid7()));
        $staleBill = $this->bill($order, $actor);
        $spareBill = $this->bill($order, $actor);
        $environment['VP_PAYMENT'] = (string) $stalePayment->id;
        $environment['VP_BILL'] = (string) $staleBill->id;
        $environment['VP_KEY'] = (string) Str::uuid7();
        DB::transaction(function () use ($environment, $actor, $stalePayment, $sparePayment, $staleBill, $spareBill): void {
            DB::table('vendor_payments')->count();
            DB::table('vendor_payment_allocations')->count();
            $this->assertSame(['ok', 'ok'], array_column($this->workers($environment, 'post'), 'status'));
            $this->assertSame(['ok', 'ok'], array_column($this->workers($environment, 'allocate'), 'status'));
            foreach ([[$stalePayment, $spareBill], [$sparePayment, $staleBill]] as [$payment, $bill]) {
                try {
                    app(VendorPaymentAllocationService::class)->allocate($actor, $payment, $bill, '100', (string) Str::uuid7());
                    $this->fail('An old outer transaction snapshot bypassed an allocation cap.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('amount', $exception->errors());
                }
            }
        });
    }

    private function bill(PurchaseOrder $order, User $actor): VendorBill
    {
        $bill = VendorBill::factory()->create(['supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id,
            'created_by' => $actor->id, 'total_amount' => '100', 'subtotal' => '100']);
        DB::table('vendor_bills')->where('id', $bill->id)->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);

        return $bill->refresh();
    }

    /** @param array<string, string> $environment
     * @param  list<array<string, string>>  $overrides
     * @return list<array{status: string, id?: int}>
     */
    private function workers(array $environment, string $operation, array $overrides = [[], []]): array
    {
        $processes = [];
        $environment['VP_OPERATION'] = $operation;
        $environment['VP_START'] = (string) (microtime(true) + 1);
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/VendorPaymentWorker.php')], base_path(), array_replace($environment, $overrides[$worker], ['VP_WORKER' => (string) $worker]));
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
