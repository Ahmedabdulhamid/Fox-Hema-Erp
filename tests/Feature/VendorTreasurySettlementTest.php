<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\CreateVendorPayment;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\ViewVendorPayment;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Purchasing\Services\VendorPaymentTreasury;
use App\Modules\Treasury\Database\Seeders\BranchCashboxSeeder;
use App\Modules\Treasury\Database\Seeders\CentralCashboxSeeder;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class VendorTreasurySettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    private Supplier $supplier;

    private VendorPaymentService $payments;

    private TreasuryService $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Settlement branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->method = PaymentMethod::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->payments = app(VendorPaymentService::class);
        $this->treasury = app(TreasuryService::class);
        $this->fund($this->cash);
    }

    private function fund(TreasuryAccount $account): void
    {
        $this->treasury->post($this->actor, $this->treasury->createDraft($this->actor, [
            'type' => 'opening_balance', 'account_id' => $account->id, 'amount' => '100.0001',
            'effective_date' => now()->format('Y-m-d'), 'reason' => 'Isolated fixture funds',
        ], (string) Str::uuid7()));
    }

    private function draft(array $changes = [], ?string $key = null): VendorPayment
    {
        return $this->payments->createDraft($this->actor, array_replace([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->branch->id,
            'payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id,
            'amount' => '60.0001', 'payment_date' => now()->format('Y-m-d'),
        ], $changes), $key ?? (string) Str::uuid7());
    }

    private function invalid(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid settlement accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized settlement accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_exact_cash_disbursement_link_and_retry_preserve_one_effect_and_supplier_advance(): void
    {
        $key = (string) Str::uuid7();
        $payment = $this->draft(key: $key);
        $this->assertSame('100.0001', $this->treasury->balance($this->actor, $this->cash));
        $posted = $this->payments->post($this->actor, $payment);
        $operation = app(VendorPaymentTreasury::class)->movement($this->actor, $posted);
        $this->assertSame($posted->id, $operation->vendor_payment_id);
        $this->assertSame('payment_disbursement', $operation->type);
        $this->assertSame($this->actor->id, $operation->posted_by);
        $this->assertSame($posted->amount, $operation->amount);
        $this->assertSame($posted->uuid, $operation->source_reference);
        $this->assertSame('out', $operation->entries()->sole()->direction);
        $this->assertSame('40.0000', $this->treasury->balance($this->actor, $this->cash));
        $this->assertSame($posted->id, $this->payments->post($this->actor, $this->draft(['amount' => '060.00010'], $key))->id);
        $this->assertSame(1, TreasuryOperation::whereNotNull('vendor_payment_id')->count());
        $this->assertSame('60.0001', app(SupplierFinancialBalances::class)->payment($this->actor, $posted)['unallocated']);
        $this->assertDatabaseCount('payments', 0);
        $this->invalid(fn () => $this->draft(['amount' => '61'], $key));
        $other = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->invalid(fn () => $this->draft(['treasury_account_id' => $other->id], $key));
    }

    public function test_bank_and_central_cash_settlements_use_explicit_authorized_accounts(): void
    {
        $bank = TreasuryAccount::factory()->bank()->create(['created_by' => $this->actor->id]);
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->actor->id]);
        $this->fund($bank);
        $this->fund($central);
        $transfer = PaymentMethod::factory()->create(['type' => 'bank_transfer', 'requires_reference' => true]);
        $this->payments->post($this->actor, $this->draft(['treasury_account_id' => $bank->id, 'payment_method_id' => $transfer->id, 'external_reference' => 'BANK-VERIFIED-1']));
        $this->payments->post($this->actor, $this->draft(['treasury_account_id' => $central->id]));
        $this->assertSame('40.0000', $this->treasury->balance($this->actor, $bank));
        $this->assertSame('40.0000', $this->treasury->balance($this->actor, $central));
    }

    public function test_insufficient_funds_leave_draft_and_no_orphan_movement(): void
    {
        $payment = $this->draft(['amount' => '100.0002']);
        $before = AuthEvent::count();
        $this->invalid(fn () => $this->payments->post($this->actor, $payment));
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->posted_at);
        $this->assertSame($before, AuthEvent::count());
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    public function test_missing_inactive_and_unsupported_sources_fail_closed(): void
    {
        $this->invalid(fn () => $this->draft(['treasury_account_id' => null]));
        $this->invalid(fn () => $this->draft(['treasury_account_id' => 999999]));
        $inactive = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'is_active' => false]);
        $this->invalid(fn () => $this->draft(['treasury_account_id' => $inactive->id]));
        foreach (['card', 'wallet', 'other', 'bank_transfer'] as $type) {
            $method = PaymentMethod::factory()->create(['type' => $type]);
            $this->invalid(fn () => $this->draft(['payment_method_id' => $method->id]));
        }
        $warehouse = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $account = TreasuryAccount::factory()->create(['type' => 'location_cashbox', 'location_id' => $warehouse->id]);
        $this->invalid(fn () => $this->draft(['treasury_account_id' => $account->id]));
    }

    public function test_source_activation_and_treasury_module_are_rechecked_at_posting(): void
    {
        $payment = $this->draft();
        app(TreasuryAccounts::class)->save($this->actor, ['is_active' => false], $this->cash);
        $this->invalid(fn () => $this->payments->post($this->actor, $payment));
        app(TreasuryAccounts::class)->save($this->actor, ['is_active' => true], $this->cash);
        Module::where('code', 'treasury')->update(['is_active' => false]);
        $this->denied(fn () => $this->payments->post($this->actor, $payment));
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    /** @return array<string, array{string}> */
    public static function permissions(): array
    {
        return ['treasury view' => [TreasuryPermissions::VIEW], 'treasury create' => [TreasuryPermissions::CREATE],
            'treasury post' => [TreasuryPermissions::POST], 'purchasing post' => [PurchasingPermissions::PAYMENTS_POST]];
    }

    #[DataProvider('permissions')]
    public function test_both_modules_permissions_are_required_even_on_retry(string $permission): void
    {
        $payment = $this->payments->post($this->actor, $this->draft());
        $this->actor->revokePermissionTo($permission);
        $this->denied(fn () => $this->payments->post($this->actor, $payment));
        $this->assertSame('40.0000', DB::table('treasury_entries')->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as balance")->value('balance'));
    }

    public function test_all_location_view_never_grants_cross_location_or_central_spending(): void
    {
        $other = TreasuryAccount::factory()->create();
        $central = TreasuryAccount::factory()->central()->create();
        $this->actor->revokePermissionTo(TreasuryPermissions::OPERATE_ALL_LOCATIONS);
        $this->denied(fn () => $this->draft(['treasury_account_id' => $other->id]));
        $this->denied(fn () => $this->draft(['treasury_account_id' => $central->id]));
        $this->assertDatabaseCount('vendor_payments', 0);
    }

    public function test_recovery_requires_evidence_and_both_permissions_and_cannot_be_faked_by_document_void(): void
    {
        $payment = $this->payments->post($this->actor, $this->draft());
        $operation = $payment->treasuryOperation;
        $this->invalid(fn () => $this->payments->reverse($this->actor, $payment, (string) Str::uuid7(), 'Void only'));
        $this->invalid(fn () => $this->treasury->reverse($this->actor, $operation, (string) Str::uuid7(), 'Bypass'));
        $this->invalid(fn () => $this->payments->recover($this->actor, $payment, (string) Str::uuid7(), 'Refund', ' '));
        $this->actor->revokePermissionTo(TreasuryPermissions::REVERSE);
        $this->denied(fn () => $this->payments->recover($this->actor, $payment, (string) Str::uuid7(), 'Refund', 'Receipt-1'));
        $this->actor->givePermissionTo(TreasuryPermissions::REVERSE);
        $key = (string) Str::uuid7();
        $result = $this->payments->recover($this->actor, $payment, $key, 'Verified supplier refund', 'Receipt-1');
        $this->assertSame('void', $result->status);
        $this->assertSame('Receipt-1', $result->recovery_reference);
        $this->assertSame('100.0001', $this->treasury->balance($this->actor, $this->cash));
        $this->assertSame($result->id, $this->payments->recover($this->actor, $payment, $key, 'Verified supplier refund', 'Receipt-1')->id);
        $this->invalid(fn () => $this->payments->recover($this->actor, $payment, $key, 'Verified supplier refund', 'Different receipt'));
        $this->invalid(fn () => $this->payments->recover($this->actor, $payment, (string) Str::uuid7(), 'Again', 'Receipt-1'));
        $this->assertSame(1, TreasuryOperation::where('reversal_of_id', $operation->id)->count());
        $this->assertSame('posted', $operation->fresh()->status);
    }

    public function test_late_purchasing_failure_rolls_back_posted_treasury_entries_and_audits(): void
    {
        $payment = $this->draft();
        $before = AuthEvent::count();
        AuthEvent::creating(function (AuthEvent $event): void {
            if ($event->event === 'vendor_payment_posted') {
                throw new \RuntimeException('Injected late failure');
            }
        });
        try {
            $this->payments->post($this->actor, $payment);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected late failure', $exception->getMessage());
        } finally {
            AuthEvent::flushEventListeners();
        }
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertDatabaseCount('treasury_entries', 1);
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertSame($before, AuthEvent::count());
    }

    public function test_pre_cutover_managed_posted_payment_is_never_deducted_or_adopted(): void
    {
        $payment = $this->draft();
        DB::table('vendor_payments')->where('id', $payment->id)->update(['treasury_account_id' => null, 'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        $before = $payment->fresh()->getAttributes();
        $this->invalid(fn () => $this->payments->post($this->actor, $payment));
        $this->invalid(fn () => $this->payments->recover($this->actor, $payment, (string) Str::uuid7(), 'Legacy', 'Fake evidence'));
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertSame('60.0001', app(SupplierFinancialBalances::class)->payment($this->actor, $payment)['unallocated']);
    }

    public function test_link_uniqueness_and_posted_source_immutability(): void
    {
        $payment = $this->payments->post($this->actor, $this->draft());
        $this->invalid(fn () => $payment->update(['treasury_account_id' => null]));
        $row = (array) DB::table('treasury_operations')->where('vendor_payment_id', $payment->id)->first();
        unset($row['id']);
        $row['uuid'] = (string) Str::uuid7();
        $row['idempotency_key'] = (string) Str::uuid7();
        $row['source_reference'] = 'different';
        $this->expectException(QueryException::class);
        DB::table('treasury_operations')->insert($row);
    }

    public function test_linked_movement_view_requires_treasury_authority(): void
    {
        $payment = $this->payments->post($this->actor, $this->draft());
        $this->actor->revokePermissionTo(TreasuryPermissions::VIEW);
        $this->denied(fn () => app(VendorPaymentTreasury::class)->movement($this->actor, $payment));
    }

    public function test_quiet_writes_and_recovery_failures_cannot_rewrite_financial_history(): void
    {
        $payment = $this->payments->post($this->actor, $this->draft());
        $this->invalid(fn () => $payment->fresh()->fill(['amount' => '1'])->saveQuietly());
        $this->invalid(fn () => $payment->fresh()->deleteQuietly());
        app(TreasuryAccounts::class)->save($this->actor, ['is_active' => false], $this->cash);
        $this->invalid(fn () => $this->payments->recover($this->actor, $payment, (string) Str::uuid7(), 'Refund', 'Receipt-1'));
        $this->assertSame('posted', $payment->fresh()->status);
        $this->assertSame(0, TreasuryOperation::whereNotNull('reversal_of_id')->count());
    }

    public function test_filament_can_create_view_and_post_the_canonical_payment_without_editing_posted_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $this->assertTrue($this->actor->canAccessPanel(Filament::getPanel('admin')));
        $resource = VendorPaymentResource::class;
        $this->assertStringContainsString('100.0001', $resource::sourceOptions($this->method->id)[$this->cash->id]);
        Livewire::test(CreateVendorPayment::class)
            ->fillForm(['supplier_id' => $this->supplier->id, 'location_id' => $this->branch->id,
                'payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id,
                'amount' => '60.0001', 'payment_date' => now()->format('Y-m-d')])
            ->call('create')->assertHasNoFormErrors();
        $payment = VendorPayment::latest('id')->firstOrFail();
        $page = ViewVendorPayment::class;
        Livewire::test($page, ['record' => $payment->uuid])->callAction('post')->assertHasNoActionErrors();
        $this->assertSame('posted', $payment->fresh()->status);
        $this->assertFalse($resource::canEdit($payment->fresh()));
        Livewire::test($page, ['record' => $payment->uuid])->assertSee($payment->treasuryOperation->uuid);
        $this->actor->revokePermissionTo(TreasuryPermissions::VIEW);
        $this->assertSame([], $resource::sourceOptions($this->method->id));
        $this->assertSame(__('Treasury viewing permission required'), $resource::movementSummary($payment));
        $this->actor->givePermissionTo(TreasuryPermissions::VIEW);
        $this->actor->revokePermissionTo(TreasuryPermissions::BALANCES);
        $this->assertSame($this->cash->name.' | EGP', $resource::sourceOptions($this->method->id)[$this->cash->id]);
    }

    public function test_treasury_failure_after_posting_rolls_back_the_vendor_document(): void
    {
        $payment = $this->draft();
        AuthEvent::creating(function (AuthEvent $event): void {
            if ($event->event === 'treasury_operation_posted') {
                throw new \RuntimeException('Injected Treasury failure');
            }
        });
        try {
            $this->payments->post($this->actor, $payment);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected Treasury failure', $exception->getMessage());
        } finally {
            AuthEvent::flushEventListeners();
        }
        $this->assertSame('draft', $payment->fresh()->status);
        $this->assertDatabaseCount('treasury_entries', 1);
        $this->assertDatabaseCount('treasury_operations', 1);
    }

    public function test_seeded_cashboxes_and_bank_options_react_to_method_without_restricting_purchase_location(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        setPermissionsTeamId(null);
        $this->actingAs($admin);
        config(['treasury.seed_actor_id' => $admin->id]);
        $other = Location::create(['name' => 'Other seeded branch', 'type' => 'branch', 'is_active' => true]);
        $warehouse = Location::create(['name' => 'Receiving warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->seed([BranchCashboxSeeder::class, CentralCashboxSeeder::class]);
        $central = TreasuryAccount::where('type', 'central_cashbox')->firstOrFail();
        $branchCash = TreasuryAccount::where('type', 'branch_cashbox')->where('location_id', $other->id)->firstOrFail();
        $bank = TreasuryAccount::factory()->bank()->create(['created_by' => $admin->id]);
        $inactive = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $admin->id, 'is_active' => false]);
        $transfer = PaymentMethod::factory()->create(['type' => 'bank_transfer', 'requires_reference' => true]);
        $inactiveMethod = PaymentMethod::factory()->create(['type' => 'cash', 'is_active' => false]);
        $card = PaymentMethod::factory()->create(['type' => 'card']);
        $options = VendorPaymentResource::sourceOptions($this->method->id);
        foreach ([$this->cash, $central, $branchCash] as $account) {
            $this->assertArrayHasKey($account->id, $options);
        }
        $this->assertArrayNotHasKey($bank->id, $options);
        $this->assertArrayNotHasKey($inactive->id, $options);
        $this->assertSame([$bank->id], array_keys(VendorPaymentResource::sourceOptions($transfer->id)));
        foreach ([null, $inactiveMethod->id, $card->id] as $method) {
            $this->assertSame([], VendorPaymentResource::sourceOptions($method));
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CreateVendorPayment::class)->assertFormFieldIsDisabled('treasury_account_id')
            ->set('data.payment_method_id', $this->method->id)->assertFormFieldIsEnabled('treasury_account_id')
            ->assertFormFieldExists('treasury_account_id', fn (Select $field): bool => array_key_exists($central->id, $field->getOptions()))
            ->set('data.treasury_account_id', $central->id)->set('data.location_id', $warehouse->id)
            ->assertFormSet(['treasury_account_id' => null])->assertFormFieldExists('treasury_account_id', fn (Select $field): bool => array_key_exists($central->id, $field->getOptions()))
            ->set('data.treasury_account_id', $central->id)->set('data.external_reference', 'OLD-REFERENCE')
            ->set('data.payment_method_id', $transfer->id)->assertFormSet(['treasury_account_id' => null, 'external_reference' => null])
            ->assertFormFieldExists('external_reference', fn (TextInput $field): bool => $field->isRequired())->assertFormFieldExists('treasury_account_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$bank->id]);
    }

    public function test_source_options_never_grant_cross_location_spending_without_operating_permission(): void
    {
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->actor->id]);
        $other = TreasuryAccount::factory()->create(['created_by' => $this->actor->id]);
        $this->actingAs($this->actor);
        $this->actor->revokePermissionTo(TreasuryPermissions::OPERATE_ALL_LOCATIONS);
        $options = VendorPaymentResource::sourceOptions($this->method->id);
        $this->assertArrayHasKey($this->cash->id, $options);
        $this->assertArrayNotHasKey($central->id, $options);
        $this->assertArrayNotHasKey($other->id, $options);
        $this->denied(fn () => $this->draft(['treasury_account_id' => $central->id]));
    }

    public function test_central_administration_pays_a_warehouse_bill_from_the_central_cashbox_once(): void
    {
        $this->actor = $this->provisionTestSuperAdmin();
        setPermissionsTeamId(null);
        $this->actingAs($this->actor);
        $warehouse = Location::create(['name' => 'Central receiving warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->actor->id]);
        $this->fund($central);
        $draft = $this->draft(['location_id' => $warehouse->id, 'treasury_account_id' => $central->id]);
        $this->assertSame('100.0001', $this->treasury->balance($this->actor, $central));
        $this->payments->post($this->actor, $draft);
        $this->payments->post($this->actor, $draft);
        $this->assertSame('40.0000', $this->treasury->balance($this->actor, $central));
        $this->assertSame(1, TreasuryOperation::where('vendor_payment_id', $draft->id)->count());
        $order = PurchaseOrder::factory()->create(['supplier_id' => $this->supplier->id, 'location_id' => $warehouse->id, 'created_by' => $this->actor->id, 'status' => 'confirmed']);
        $bill = VendorBill::factory()->create(['supplier_id' => $this->supplier->id, 'purchase_order_id' => $order->id, 'created_by' => $this->actor->id, 'subtotal' => '60.0001', 'total_amount' => '60.0001']);
        DB::table('vendor_bills')->where('id', $bill->id)->update(['status' => 'posted', 'posted_by' => $this->actor->id, 'posted_at' => now()]);
        $allocation = app(VendorPaymentAllocationService::class)->allocate($this->actor, $draft->fresh(), $bill->fresh(), '60.0001', (string) Str::uuid7());
        $this->assertSame($bill->id, $allocation->vendor_bill_id);
        $this->assertSame('0.0000', app(SupplierFinancialBalances::class)->payment($this->actor, $draft->fresh())['unallocated']);
    }
}
