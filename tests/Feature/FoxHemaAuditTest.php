<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\UserLocationManager;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\FifoLayerValue;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class FoxHemaAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private Location $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Audit Branch 1', 'type' => 'branch', 'is_active' => true]);
        $this->otherBranch = Location::create(['name' => 'Audit Branch 2', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...SalesPermissions::all(),
            ...InventoryPermissions::all(), ...ProductPermissions::all()]);
        $this->actingAs($this->actor);
    }

    private function invoice(Location $location): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => $location->id, 'created_by' => $this->actor->id,
            'subtotal' => '100', 'total_amount' => '100']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id,
        ]);

        return $invoice->fresh();
    }

    #[Group('audit-defect')]
    public function test_fh01_financial_read_checks_persisted_invoice_ownership(): void
    {
        $foreign = $this->invoice($this->otherBranch);
        $this->assertFalse(app(SalesAccess::class)->allows($this->actor, SalesPermissions::INVOICES_VIEW, $foreign->location_id));
        $foreign->location_id = $this->branch->id;

        $this->expectException(HttpException::class);
        app(CustomerFinancials::class)->invoice($this->actor, $foreign);
    }

    #[Group('audit-defect')]
    public function test_fh02_invoice_financial_read_uses_stored_amounts(): void
    {
        $invoice = $this->invoice($this->branch);
        $invoice->total_amount = '999';

        $this->assertSame('100.0000', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['outstanding']);
    }

    #[Group('audit-defect')]
    public function test_fh03_all_customer_history_can_filter_linked_invoices(): void
    {
        $customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Audit customer']);
        $invoice = $this->invoice($this->branch);
        app(CustomerAccounts::class)->linkInvoice($this->actor, $customer, $invoice, [], (string) Str::uuid7());
        $financials = app(CustomerFinancials::class);
        $this->assertSame(1, $financials->history($this->actor, $customer->id, null, null, '', 'invoice')->count());

        $this->assertSame(1, $financials->history($this->actor, null, null, null, '', 'invoice')->count());
    }

    #[Group('audit-defect')]
    public function test_fh04_fifo_panel_respects_exact_layer_residual_value(): void
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => Category::factory()])->id]);
        InventoryValuationSetting::create(['category_id' => $variant->product->category_id, 'costing_method' => 'fifo']);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '149', 'quantity_reserved' => '0', 'quantity_available' => '149']);
        InventoryCostState::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'on_hand_qty' => '149', 'avg_cost' => '0.333333', 'total_value' => '49.6667']);
        $movement = StockMovement::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'type' => 'purchase', 'quantity' => '300', 'unit_cost' => '0.3333']);
        $layer = FifoLayer::factory()->create(['stock_movement_id' => $movement->id, 'variant_id' => $variant->id,
            'quantity' => '300', 'remaining_qty' => '149', 'unit_cost' => '0.333333']);
        DB::table('fifo_layers')->where('id', $layer->id)->update([
            'initial_value' => '100', 'remaining_value' => '49.6667', 'available_at' => now(),
        ]);
        $this->assertSame('49.6667', (string) app(FifoLayerValue::class)->remaining($layer->fresh()));
        $valuation = app(VariantInventoryValuation::class)->read($this->actor, $variant, $this->branch->id);

        $this->assertFalse($valuation['fifo_mismatch'], 'Reconciled FIFO layer reported inconsistent: '.$valuation['fifo_value']);
    }

    #[Group('audit-defect')]
    public function test_fh05_customer_balance_aggregates_do_not_grow_per_invoice(): void
    {
        $customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Audit query count']);
        for ($index = 0; $index < 6; $index++) {
            app(CustomerAccounts::class)->linkInvoice($this->actor, $customer, $this->invoice($this->branch), [], (string) Str::uuid7());
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $balances = app(CustomerFinancials::class)->customer($this->actor, $customer);
            $aggregates = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains(strtolower($query['query']), 'sum('));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame('600.0000', $balances['outstanding']);
        $this->assertLessThanOrEqual(2, count($aggregates), 'Customer balance should aggregate invoice credits and allocations in bulk.');
    }

    /** @return array<string, array{string, string}> */
    public static function branchProfiles(): array
    {
        return ['manager' => ['branch', 'manager'], 'cashier' => ['branch', 'cashier'],
            'warehouse' => ['warehouse', 'warehouse'], 'purchasing' => ['warehouse', 'purchasing'],
            'central' => ['branch', 'central']];
    }

    public function test_membership_management_follows_existing_owned_location_convention(): void
    {
        $this->actor->givePermissionTo('users.update');
        $this->actor->locations()->attach($this->otherBranch);
        $target = User::factory()->create(['is_active' => true]);
        $target->locations()->attach($this->branch);
        setPermissionsTeamId($this->otherBranch->id);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($this->actor->can('users.update'));
        setPermissionsTeamId($this->branch->id);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue($this->actor->can('users.update'));

        app(UserLocationManager::class)->attach($target, $this->otherBranch);
        $this->assertTrue($target->locations()->whereKey($this->otherBranch->id)->exists());
    }

    #[DataProvider('branchProfiles')]
    public function test_explicit_permission_profiles_cannot_cross_locations(string $type, string $profile): void
    {
        if ($type === 'warehouse') {
            $this->branch = Location::create(['name' => 'Audit central warehouse', 'type' => 'warehouse', 'is_active' => true]);
            $this->actor->locations()->attach($this->branch);
            setPermissionsTeamId($this->branch->id);
        }
        $this->actor->syncPermissions(match ($profile) {
            'cashier' => [SalesPermissions::VIEW],
            'warehouse' => [InventoryPermissions::BALANCES],
            'purchasing' => [PurchasingPermissions::ORDERS_VIEW],
            'central' => [TreasuryPermissions::VIEW, TreasuryPermissions::VIEW_ALL_LOCATIONS],
            default => [SalesPermissions::VIEW, InventoryPermissions::BALANCES, PurchasingPermissions::ORDERS_VIEW, TreasuryPermissions::VIEW],
        });
        $this->assertSame(in_array($profile, ['cashier', 'manager'], true), app(SalesAccess::class)->allows($this->actor, SalesPermissions::VIEW, $this->branch->id));
        $this->assertSame(in_array($profile, ['warehouse', 'manager'], true), app(InventoryAccess::class)->allows($this->actor, InventoryPermissions::BALANCES, $this->branch->id));
        $this->assertSame(in_array($profile, ['purchasing', 'manager'], true), app(PurchasingAccess::class)->allows($this->actor, PurchasingPermissions::ORDERS_VIEW, $this->branch->id));
        $this->assertFalse(app(SalesAccess::class)->allows($this->actor, SalesPermissions::VIEW, $this->otherBranch->id));
        $this->assertFalse(app(InventoryAccess::class)->allows($this->actor, InventoryPermissions::BALANCES, $this->otherBranch->id));
        $this->assertFalse(app(PurchasingAccess::class)->allows($this->actor, PurchasingPermissions::ORDERS_VIEW, $this->otherBranch->id));
        if ($profile === 'central') {
            app(TreasuryAccess::class)->authorize($this->actor, TreasuryPermissions::VIEW, null);
            $permission = TreasuryPermissions::POST;
        } else {
            $permission = TreasuryPermissions::VIEW;
        }
        $this->expectException(HttpException::class);
        app(TreasuryAccess::class)->authorize($this->actor, $permission, null);
    }

    public function test_global_super_admin_has_explicit_cross_branch_scope(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        SalesOrder::factory()->create(['location_id' => $this->branch->id]);
        SalesOrder::factory()->create(['location_id' => $this->otherBranch->id]);
        $this->assertSame(2, app(SalesAccess::class)->scopeOrders(SalesOrder::query(), $admin)->count());
        app(InventoryAccess::class)->authorize($admin, InventoryPermissions::BALANCES, $this->otherBranch->id);
        app(TreasuryAccess::class)->authorize($admin, TreasuryPermissions::VIEW, null);

    }

    public function test_supplier_bill_settlement_works_when_method_precedes_source_selection(): void
    {
        $this->actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $supplier = Supplier::factory()->create();
        $order = PurchaseOrder::factory()->create(['supplier_id' => $supplier->id,
            'location_id' => $this->branch->id, 'created_by' => $this->actor->id, 'status' => 'confirmed']);
        $bill = VendorBill::factory()->create(['supplier_id' => $supplier->id, 'purchase_order_id' => $order->id,
            'subtotal' => '100', 'total_amount' => '100', 'created_by' => $this->actor->id]);
        DB::table('vendor_bills')->where('id', $bill->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id,
        ]);
        $cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, [
            'type' => 'opening_balance', 'account_id' => $cash->id, 'amount' => '200',
            'effective_date' => now()->toDateString(), 'reason' => 'Disposable audit funds',
        ], (string) Str::uuid7()));

        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->callAction('settleBill', [
            'supplier_id' => $supplier->id, 'location_id' => $this->branch->id,
            'payment_method_id' => $method->id, 'treasury_account_id' => $cash->id,
            'payment_date' => now()->toDateString(), 'amount' => '100',
            'operation_key' => (string) Str::uuid7(), 'allocation_key' => (string) Str::uuid7(),
        ])->assertHasNoActionErrors();
        $this->assertDatabaseCount('vendor_payment_allocations', 1);
        $this->assertSame('100.0000', $treasury->balance($this->actor, $cash));
    }
}
