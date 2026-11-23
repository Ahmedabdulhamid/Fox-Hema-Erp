<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class VendorPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private Supplier $supplier;

    private PaymentMethod $method;

    private VendorPaymentService $payments;

    private TreasuryAccount $cash;

    private VendorPaymentAllocationService $allocations;

    private SupplierFinancialBalances $balances;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Vendor payments branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, ['type' => 'opening_balance', 'account_id' => $this->cash->id, 'amount' => '1000000', 'effective_date' => '2026-10-24', 'reason' => 'Isolated test funds'], (string) Str::uuid7()));
        $this->supplier = Supplier::factory()->create();
        $this->method = PaymentMethod::factory()->create();
        $this->payments = app(VendorPaymentService::class);
        $this->allocations = app(VendorPaymentAllocationService::class);
        $this->balances = app(SupplierFinancialBalances::class);
    }

    private function draft(array $changes = [], ?string $key = null): VendorPayment
    {
        return $this->payments->createDraft($this->actor, array_replace([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->branch->id,
            'treasury_account_id' => $this->cash->id, 'payment_method_id' => $this->method->id, 'payment_date' => '2026-10-24', 'amount' => '10000',
        ], $changes), $key ?? (string) Str::uuid7());
    }

    private function recover(User $actor, VendorPayment $payment, string $key, string $reason): VendorPayment
    {
        return $this->payments->recover($actor, $payment, $key, $reason, 'Verified test recovery');
    }

    private function posted(string $amount = '10000'): VendorPayment
    {
        return $this->payments->post($this->actor, $this->draft(['amount' => $amount]));
    }

    /** Posted bill fixture isolates settlement behavior from receipt matching (covered by bill workflow tests). */
    private function bill(string $total = '25000', ?Supplier $supplier = null, ?Location $location = null, string $status = 'posted'): VendorBill
    {
        $order = PurchaseOrder::factory()->create(['supplier_id' => ($supplier ?? $this->supplier)->id,
            'location_id' => ($location ?? $this->branch)->id, 'created_by' => $this->actor->id, 'status' => 'confirmed']);
        $bill = VendorBill::factory()->create(['supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id,
            'created_by' => $this->actor->id, 'total_amount' => $total, 'subtotal' => $total]);
        DB::table('vendor_bills')->where('id', $bill->id)->update(['status' => $status, 'posted_by' => $this->actor->id, 'posted_at' => now()]);

        return $bill->refresh();
    }

    private function allocate(VendorPayment $payment, VendorBill $bill, string $amount, ?string $key = null): VendorPaymentAllocation
    {
        return $this->allocations->allocate($this->actor, $payment, $bill, $amount, $key ?? (string) Str::uuid7());
    }

    private function invalid(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid financial operation accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized financial operation accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_draft_post_and_history_are_idempotent_and_owned_by_purchasing(): void
    {
        $key = (string) Str::uuid7();
        $draft = $this->draft(key: $key);
        $this->assertSame('draft', $draft->status);
        $this->assertSame(['allocated' => '0.0000', 'unallocated' => '0.0000'], $this->balances->payment($this->actor, $draft));
        $this->assertSame($draft->id, $this->draft(['amount' => '010000.00000'], $key)->id);
        $posted = $this->payments->post($this->actor, $draft);
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame($posted->getAttributes(), $this->payments->post($this->actor, $draft)->getAttributes());
        $this->assertSame($posted->id, $this->payments->history($this->actor, $this->supplier->id)->sole()->id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame(1, AuthEvent::where('event', 'vendor_payment_posted')->count());
    }

    public function test_full_partial_and_multiple_payments_preserve_document_status(): void
    {
        $bill = $this->bill('10000');
        $first = $this->posted('4000');
        $this->allocate($first, $bill, '4000');
        $this->assertSame(['paid' => '4000.0000', 'outstanding' => '6000.0000'], $this->balances->bill($this->actor, $bill));
        $this->allocate($this->posted('6000'), $bill, '6000');
        $this->assertSame(['paid' => '10000.0000', 'outstanding' => '0.0000'], $this->balances->bill($this->actor, $bill));
        $this->assertSame('posted', $bill->fresh()->status);
    }

    public function test_one_payment_settles_multiple_bills_atomically(): void
    {
        $payment = $this->posted();
        $first = $this->bill('3000');
        $second = $this->bill('7000');
        $rows = [['bill_id' => $second->id, 'amount' => '7000', 'operation_key' => (string) Str::uuid7()],
            ['bill_id' => $first->id, 'amount' => '3000', 'operation_key' => (string) Str::uuid7()]];
        $this->assertCount(2, $this->allocations->allocateMany($this->actor, $payment, $rows));
        $this->assertCount(2, $this->allocations->allocateMany($this->actor, $payment, $rows));
        $this->assertDatabaseCount('vendor_payment_allocations', 2);
        $this->assertSame('0.0000', $this->balances->payment($this->actor, $payment)['unallocated']);
    }

    public function test_advance_is_allocated_later_in_distinct_immutable_tranches(): void
    {
        $payment = $this->posted();
        $this->assertSame(['gross_open_invoices' => '0.0000', 'unallocated_advances' => '10000.0000', 'net_supplier_balance' => '-10000.0000'], $this->balances->supplier($this->actor, $this->supplier->id));
        $this->travel(5)->days();
        $bill = $this->bill();
        $first = $this->allocate($payment, $bill, '4000');
        $second = $this->allocate($payment, $bill, '6000');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('2026-10-24', $payment->fresh()->payment_date->format('Y-m-d'));
        $this->assertTrue($first->allocated_at->greaterThan($payment->posted_at));
        $this->assertSame(['gross_open_invoices' => '15000.0000', 'unallocated_advances' => '0.0000', 'net_supplier_balance' => '15000.0000'], $this->balances->supplier($this->actor, $this->supplier->id));
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertSame(1, TreasuryOperation::whereNotNull('vendor_payment_id')->count());
    }

    public function test_partial_advance_and_draft_bills_do_not_distort_supplier_debt(): void
    {
        $payment = $this->posted();
        $bill = $this->bill('25000');
        $this->bill('99999', status: 'draft');
        $this->draft(['amount' => '99999']);
        $this->allocate($payment, $bill, '4000');
        $this->assertSame(['gross_open_invoices' => '21000.0000', 'unallocated_advances' => '6000.0000', 'net_supplier_balance' => '15000.0000'], $this->balances->supplier($this->actor, $this->supplier->id));
    }

    public function test_payment_and_bill_caps_are_enforced_independently(): void
    {
        $payment = $this->posted('100');
        $bill = $this->bill('200');
        $this->invalid(fn () => $this->allocate($payment, $bill, '100.0001'));
        $this->allocate($payment, $bill, '100');
        $other = $this->posted('200');
        $this->invalid(fn () => $this->allocate($other, $bill, '100.0001'));
        $this->assertDatabaseCount('vendor_payment_allocations', 1);
    }

    public function test_cross_supplier_unknown_ownership_and_cross_location_are_rejected(): void
    {
        $payment = $this->posted();
        $this->invalid(fn () => $this->allocate($payment, $this->bill(supplier: Supplier::factory()->create()), '1'));
        $other = Location::create(['name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $this->invalid(fn () => $this->allocate($payment, $this->bill(location: $other), '1'));
        $legacy = VendorBill::factory()->create(['supplier_id' => $this->supplier->id]);
        $this->invalid(fn () => $this->allocate($payment, $legacy, '1'));
        $this->assertDatabaseCount('vendor_payment_allocations', 0);
    }

    public function test_only_posted_documents_can_be_allocated(): void
    {
        $bill = $this->bill();
        $this->invalid(fn () => $this->allocate($this->draft(), $bill, '1'));
        $posted = $this->posted();
        $this->invalid(fn () => $this->allocate($posted, $this->bill(status: 'draft'), '1'));
        $this->invalid(fn () => $this->allocate($posted, $this->bill(status: 'cancelled'), '1'));
        $this->recover($this->actor, $posted, (string) Str::uuid7(), 'Correction');
        $this->invalid(fn () => $this->allocate($posted, $bill, '1'));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAmounts(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'float' => [0.1], 'exponent' => ['1e3'],
            'precision' => ['1.00001'], 'overflow' => ['10000000000000000'], 'missing' => [null], 'array' => [[]]];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected_for_drafts_and_allocations(mixed $amount): void
    {
        $this->invalid(fn () => $this->draft(['amount' => $amount]));
        $payment = $this->posted();
        $bill = $this->bill();
        $this->invalid(fn () => $this->allocations->allocate($this->actor, $payment, $bill, $amount, (string) Str::uuid7()));
    }

    public function test_currency_protected_fields_and_operation_keys_are_validated(): void
    {
        foreach ([['currency' => 'USD'], ['status' => 'posted'], ['posted_by' => $this->actor->id], ['reversal_reason' => 'fake'], ['supplier_id' => 999999], ['payment_date' => 'invalid']] as $changes) {
            $this->invalid(fn () => $this->draft($changes));
        }
        $this->invalid(fn () => $this->draft(key: 'bad'));
        $this->assertDatabaseCount('vendor_payments', 0);
    }

    public function test_method_governance_is_rechecked_on_posting(): void
    {
        $this->method->update(['requires_reference' => true]);
        $this->invalid(fn () => $this->draft());
        $draft = $this->draft(['external_reference' => 'TRANSFER-1']);
        $this->method->update(['is_active' => false]);
        $this->invalid(fn () => $this->payments->post($this->actor, $draft));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_conflicting_create_and_allocation_retries_are_rejected(): void
    {
        $key = (string) Str::uuid7();
        $payment = $this->draft(key: $key);
        $this->invalid(fn () => $this->draft(['amount' => '2'], $key));
        $payment = $this->payments->post($this->actor, $payment);
        $bill = $this->bill();
        $allocationKey = (string) Str::uuid7();
        $row = $this->allocate($payment, $bill, '1', $allocationKey);
        $this->assertSame($row->id, $this->allocate($payment, $bill, '1.0000', $allocationKey)->id);
        $this->invalid(fn () => $this->allocate($payment, $bill, '2', $allocationKey));
        $this->invalid(fn () => $this->allocate($payment, $this->bill(), '1', $allocationKey));
        $this->assertDatabaseCount('vendor_payment_allocations', 1);
    }

    public function test_reversal_preserves_evidence_and_restores_all_invoices_once(): void
    {
        $payment = $this->posted();
        $first = $this->bill('4000');
        $second = $this->bill('6000');
        $row = $this->allocate($payment, $first, '4000');
        $this->allocate($payment, $second, '6000');
        $before = $row->fresh()->getAttributes();
        $key = (string) Str::uuid7();
        $reversed = $this->recover($this->actor, $payment, $key, 'Wrong supplier transfer');
        $this->assertSame('void', $reversed->status);
        $this->assertSame($this->actor->id, $reversed->voided_by);
        $this->assertNotNull($reversed->posted_at);
        $this->assertNotNull($reversed->voided_at);
        $this->assertSame('Wrong supplier transfer', $reversed->reversal_reason);
        $this->assertSame($before, $row->fresh()->getAttributes());
        $this->assertSame('4000.0000', $this->balances->bill($this->actor, $first)['outstanding']);
        $this->assertSame('6000.0000', $this->balances->bill($this->actor, $second)['outstanding']);
        $this->assertSame('0.0000', $this->balances->payment($this->actor, $payment)['unallocated']);
        $this->assertSame($reversed->id, $this->recover($this->actor, $payment, $key, 'Wrong supplier transfer')->id);
        $this->invalid(fn () => $this->recover($this->actor, $payment, $key, 'Changed'));
        $this->invalid(fn () => $this->recover($this->actor, $payment, (string) Str::uuid7(), 'Again'));
        $this->invalid(fn () => $this->recover($this->actor, $this->draft(), (string) Str::uuid7(), 'Draft'));
        $this->assertSame(1, AuthEvent::where('event', 'vendor_payment_reversed')->count());
    }

    public function test_allocation_release_restores_advance_and_retains_original_tranche(): void
    {
        $payment = $this->posted('100');
        $bill = $this->bill('100');
        $row = $this->allocate($payment, $bill, '100');
        $key = (string) Str::uuid7();
        $released = $this->allocations->release($this->actor, $row, $key, 'Wrong invoice');
        $this->assertSame('100.0000', $released->amount);
        $this->assertSame('100.0000', $this->balances->payment($this->actor, $payment)['unallocated']);
        $this->assertSame('100.0000', $this->balances->bill($this->actor, $bill)['outstanding']);
        $this->assertSame($row->id, $this->allocations->release($this->actor, $row, $key, 'Wrong invoice')->id);
        $this->invalid(fn () => $this->allocations->release($this->actor, $row, $key, 'Changed'));
        $this->invalid(fn () => $this->allocations->release($this->actor, $row, (string) Str::uuid7(), 'Duplicate'));
        $this->allocate($payment, $bill, '100');
        $this->assertDatabaseCount('vendor_payment_allocations', 2);
        $this->assertSame('100.0000', $this->balances->bill($this->actor, $bill)['paid']);
    }

    public function test_payment_and_allocation_immutability_includes_stale_instances(): void
    {
        $draft = $this->draft();
        $stale = $draft->fresh();
        $posted = $this->payments->post($this->actor, $draft);
        foreach (['amount' => '1', 'supplier_id' => Supplier::factory()->create()->id, 'payment_method_id' => 999999, 'location_id' => 999999, 'status' => 'draft'] as $field => $value) {
            $this->invalid(fn () => $posted->fresh()->update([$field => $value]));
        }
        $this->invalid(fn () => $stale->update(['notes' => 'stale']));
        $this->invalid(fn () => $posted->delete());
        $row = $this->allocate($posted, $this->bill(), '1');
        $this->invalid(fn () => $row->update(['amount' => '2']));
        $this->invalid(fn () => $row->delete());
        $this->invalid(fn () => VendorPaymentAllocation::create(['supplier_id' => $posted->supplier_id, 'vendor_payment_id' => $posted->id, 'vendor_bill_id' => $row->vendor_bill_id, 'amount' => '1']));
    }

    public function test_atomic_batch_and_outer_transaction_failures_roll_back_financial_and_audit_rows(): void
    {
        $payment = $this->posted('100');
        $first = $this->bill('50');
        $second = $this->bill('10');
        $before = AuthEvent::count();
        $this->invalid(fn () => $this->allocations->allocateMany($this->actor, $payment, [
            ['bill_id' => $first->id, 'amount' => '50', 'operation_key' => (string) Str::uuid7()],
            ['bill_id' => $second->id, 'amount' => '11', 'operation_key' => (string) Str::uuid7()],
        ]));
        $this->assertDatabaseCount('vendor_payment_allocations', 0);
        $this->assertSame($before, AuthEvent::count());
        try {
            DB::transaction(function (): void {
                $this->posted();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertSame($before, AuthEvent::count());
    }

    public function test_branch_financial_totals_are_scoped_and_all_location_view_does_not_grant_writes(): void
    {
        $payment = $this->posted('100');
        $this->bill('200');
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($other);
        setPermissionsTeamId($other->id);
        $this->actor->unsetRelation('permissions')->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $this->draft(['location_id' => $other->id, 'amount' => '900']);
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertCount(1, $this->payments->history($this->actor, $this->supplier->id));
        $this->assertSame('0.0000', $this->balances->supplier($this->actor, $this->supplier->id)['net_supplier_balance']);
        $this->denied(fn () => $this->balances->payment($this->actor, $payment));
        $this->actor->givePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertSame('100.0000', $this->balances->supplier($this->actor, $this->supplier->id)['net_supplier_balance']);
        $this->assertCount(2, $this->payments->history($this->actor, $this->supplier->id));
        $this->denied(fn () => $this->recover($this->actor, $payment, (string) Str::uuid7(), 'Denied'));
    }

    public function test_permissions_modules_and_inactive_actors_are_enforced_on_retries(): void
    {
        $draft = $this->draft();
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_POST);
        $this->denied(fn () => $this->payments->post($this->actor, $draft));
        $this->actor->givePermissionTo(PurchasingPermissions::PAYMENTS_POST);
        $payment = $this->payments->post($this->actor, $draft);
        $bill = $this->bill();
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_ALLOCATE);
        $this->denied(fn () => $this->allocate($payment, $bill, '1'));
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_REVERSE);
        $this->denied(fn () => $this->recover($this->actor, $payment, (string) Str::uuid7(), 'Denied'));
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => false]);
        $this->denied(fn () => $this->payments->post($this->actor, $payment));
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $this->actor->update(['is_active' => false]);
        $this->denied(fn () => $this->payments->history($this->actor, $this->supplier->id));
    }

    public function test_ordinary_users_are_not_granted_new_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->locations()->attach($this->branch);
        $this->assertCount(0, $user->getAllPermissions());
        $this->denied(fn () => $this->payments->history($user, $this->supplier->id));
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        $this->denied(fn () => $this->draft());
    }

    public function test_legacy_records_are_preserved_and_ambiguous_balances_fail_closed(): void
    {
        $legacy = VendorPayment::factory()->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->actor->id]);
        $legacy->update(['payment_date' => '2026-10-01', 'notes' => 'Existing draft workflow']);
        $this->assertSame('2026-10-01', $legacy->fresh()->payment_date->format('Y-m-d'));
        $this->invalid(fn () => $legacy->fresh()->update(['location_id' => $this->branch->id]));
        DB::table('vendor_payments')->where('id', $legacy->id)->update(['status' => 'posted']);
        $before = $legacy->fresh()->getAttributes();
        $this->invalid(fn () => $this->payments->post($this->actor, $legacy));
        $this->invalid(fn () => $this->recover($this->actor, $legacy, (string) Str::uuid7(), 'Legacy'));
        $this->invalid(fn () => $this->balances->supplier($this->actor, $this->supplier->id));
        $this->invalid(fn () => $legacy->fresh()->update(['notes' => 'Rewrite posted history']));
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertCount(0, $this->payments->history($this->actor, $this->supplier->id));
    }

    public function test_tampered_draft_cannot_be_posted(): void
    {
        $draft = $this->draft();
        DB::table('vendor_payments')->where('id', $draft->id)->update(['amount' => '9']);
        $this->invalid(fn () => $this->payments->post($this->actor, $draft));
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_twenty_five_thousand_payment_settles_two_bills_without_duplicate_allocation(): void
    {
        $first = $this->bill('20000');
        $second = $this->bill('15000');
        $payment = $this->posted('25000');
        $rows = [['bill_id' => $first->id, 'amount' => '20000', 'operation_key' => (string) Str::uuid7()],
            ['bill_id' => $second->id, 'amount' => '5000', 'operation_key' => (string) Str::uuid7()]];
        $this->allocations->allocateMany($this->actor, $payment, $rows);
        $this->allocations->allocateMany($this->actor, $payment, $rows);
        $this->assertSame(['paid' => '20000.0000', 'outstanding' => '0.0000'], $this->balances->bill($this->actor, $first));
        $this->assertSame(['paid' => '5000.0000', 'outstanding' => '10000.0000'], $this->balances->bill($this->actor, $second));
        $this->assertSame(['allocated' => '25000.0000', 'unallocated' => '0.0000'], $this->balances->payment($this->actor, $payment));
        $this->assertDatabaseCount('vendor_payment_allocations', 2);
        $this->assertSame(1, TreasuryOperation::where('vendor_payment_id', $payment->id)->count());
        $this->invalid(fn () => $this->allocate($payment, $second, '0.0001'));
    }
}
