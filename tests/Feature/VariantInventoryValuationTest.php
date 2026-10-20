<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Filament\VariantInventoryValuationPanel;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\PurchasingPhase3TestSetup;
use Tests\TestCase;

class VariantInventoryValuationTest extends TestCase
{
    // Application bootstrap only: do not invoke the migration or cleanup helper.
    use PurchasingPhase3TestSetup;

    private User $actor;

    private Location $location;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', (string) getenv('DB_DATABASE'))) {
            throw new LogicException('Select an existing isolated purchasing test database explicitly.');
        }
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (DB::getDriverName() !== 'mysql' || $database !== getenv('DB_DATABASE')
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database
            || ! Schema::hasColumns('inventory_cost_states', ['location_id', 'legacy_variant_id'])) {
            throw new LogicException('Existing isolated schema required; never migrate or seed.');
        }
        DB::beginTransaction();
        Module::whereIn('code', ['products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        setPermissionsTeamId(null);
        $this->actor = User::where('email', 'admin@example.test')->firstOrFail();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $this->withSession(['locale' => 'en']);
        $this->location = Location::create(['name' => 'Valuation Central Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        $this->variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create([
            'name' => 'Valuation P_1', 'category_id' => Category::factory()->create()->id, 'is_active' => true, 'status' => 'active',
        ])->id, 'cost_price' => '2000', 'sale_price' => '2500']);
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'avco']);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function panel(): array
    {
        return ['variantId' => $this->variant->id, 'productId' => $this->variant->product_id];
    }

    private function stock(Location $location, string $quantity = '50', string $average = '200', string $value = '10000'): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $location->id,
            'quantity_on_hand' => $quantity, 'quantity_reserved' => '0', 'quantity_available' => $quantity]);
        InventoryCostState::create(['variant_id' => $this->variant->id, 'location_id' => $location->id,
            'on_hand_qty' => $quantity, 'avg_cost' => $average, 'total_value' => $value]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['product_variants', 'stock_balances', 'stock_movements', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers', 'inventory_standard_costs', 'inventory_standard_cost_revisions', 'purchase_receipts', 'purchase_receipt_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_avco_panel_is_read_only_and_switches_between_location_costs(): void
    {
        $other = Location::create(['name' => 'Valuation Other Warehouse', 'type' => 'warehouse']);
        $this->stock($this->location);
        $this->stock($other, '12.5', '350', '4375');
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        $before = $this->snapshot();
        $panel = Livewire::test(VariantInventoryValuationPanel::class, $this->panel())
            ->assertSet('locationId', (string) $this->location->id)->assertSee('EGP 200.00')->assertSee('EGP 10,000.00')
            ->assertSee('EGP 2,000.00')->assertSee('EGP 2,500.00')->assertDontSee('50.0000');
        $panel->set('locationId', (string) $other->id)->assertSee('12.5')->assertSee('EGP 350.00')->assertDontSee('EGP 10,000.00');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_zero_and_mismatched_valuation_are_explicit(): void
    {
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('Not valued');
        $this->stock($this->location, '0', '0', '0');
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('EGP 0.00')->assertDontSee('Not valued');
        StockBalance::where('variant_id', $this->variant->id)->update(['quantity_on_hand' => '1', 'quantity_available' => '1']);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('Inventory quantities or FIFO values disagree.');
    }

    public function test_fifo_layers_and_aggregate_are_location_scoped_and_paginated(): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => 'fifo']);
        $other = Location::create(['name' => 'Hidden layer warehouse', 'type' => 'warehouse']);
        foreach ([$this->location, $other] as $location) {
            $movement = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $location->id, 'type' => 'purchase', 'quantity' => '12.5']);
            FifoLayer::create(['variant_id' => $this->variant->id, 'stock_movement_id' => $movement->id,
                'quantity' => '12.5', 'remaining_qty' => '10.25', 'unit_cost' => $location->is($other) ? '9876' : '200', 'received_at' => now()]);
        }
        $before = $this->snapshot();
        $data = app(VariantInventoryValuation::class)->read($this->actor, $this->variant, $this->location->id);
        $this->assertSame(1, $data['layers']->total());
        $this->assertSame(10, $data['layers']->perPage());
        $this->assertSame('2050.0000', $data['fifo_value']);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->set('locationId', (string) $this->location->id)
            ->assertSee('10.25')->assertSee('EGP 2,050.00')->assertDontSee('9,876');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_standard_display_uses_effective_revisions_and_rejects_conflicting_ties(): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => 'standard']);
        $master = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '999']);
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '100', 'effective_at' => now()->subDays(2)]);
        $current = InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '175.123456', 'effective_at' => now()->subDay()]);
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '999', 'effective_at' => now()->addDay()]);
        $before = $this->snapshot();
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('EGP 175.12')->assertDontSee('EGP 999.00');
        $this->assertSame($before, $this->snapshot());
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '180', 'effective_at' => $current->effective_at]);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('Conflicting standard-cost revisions')->assertDontSee('EGP 175.12');
    }

    private function scoped(): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->location);
        app(LocationContext::class)->setFor($user, $this->location->id);
        $role = Role::create(['name' => 'valuation-reader-'.$user->id, 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo(['products.view', InventoryPermissions::BALANCES, InventoryPermissions::COST]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_location_tampering_is_denied_and_reference_cost_is_independently_hidden(): void
    {
        $other = Location::create(['name' => 'Secret warehouse', 'type' => 'warehouse']);
        $user = $this->scoped();
        $panel = Livewire::test(VariantInventoryValuationPanel::class, $this->panel())
            ->assertDontSee('Secret warehouse')->assertDontSee('EGP 2,000.00');
        $this->assertSame([$this->location->id], app(VariantInventoryValuation::class)->locations($user)->pluck('id')->all());
        $panel->set('locationId', (string) $other->id)->assertForbidden();
    }

    public function test_open_panel_rechecks_permissions_and_module_activation(): void
    {
        $user = $this->scoped();
        $panel = Livewire::test(VariantInventoryValuationPanel::class, $this->panel());
        $user->roles->first()->revokePermissionTo(InventoryPermissions::COST);
        $panel->call('$refresh')->assertForbidden();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $panel = Livewire::test(VariantInventoryValuationPanel::class, $this->panel());
        Module::where('code', 'inventory_costing')->update(['is_active' => false]);
        $panel->call('$refresh')->assertForbidden();
    }

    public function test_variant_identity_and_parent_product_are_protected(): void
    {
        $other = Product::factory()->create();
        Livewire::test(VariantInventoryValuationPanel::class, ['variantId' => $this->variant->id, 'productId' => $other->id])->assertNotFound();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->set('variantId', 999);
    }

    public function test_variant_relation_manager_opens_the_integrated_read_only_panel(): void
    {
        $this->stock($this->location);
        $before = $this->snapshot();
        $manager = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->variant->product, 'pageClass' => ViewProduct::class])
            ->mountAction(TestAction::make('inventoryValuation')->table($this->variant))
            ->assertActionMounted(TestAction::make('inventoryValuation')->table($this->variant));
        $this->assertStringContainsString('EGP 200.00', $manager->instance()->getMountedAction()->getModalContent()->render());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_quantity_displays_and_fractional_receipt_inputs_remain_independent(): void
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
            'location_id' => $this->location->id, 'order_date' => today()->toDateString(),
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '12.5', 'unit_price' => '200']]], (string) Str::uuid7());
        $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->assertSee('12.5')->assertDontSee('12.5000');
        Livewire::test(EditPurchaseReceipt::class, ['record' => $receipt->uuid])
            ->fillForm(['items' => [['id' => $receipt->items->first()->id, 'quantity' => '3.1250', 'unit_cost' => '200.000000']]])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('3.1250', $receipt->items()->first()->quantity);
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->assertSee('3.125')->assertDontSee('3.1250');
        $this->stock($this->location, '12.5', '200', '2500');
        Livewire::test(ListStockBalances::class)->assertSee('12.5')->assertDontSee('12.5000');
        StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'sale', 'quantity' => '-5.5']);
        Livewire::test(ListStockMovements::class)->assertSee('-5.5')->assertDontSee('-5.5000');
    }

    public function test_actual_posted_receipt_is_the_latest_purchase_source_without_price_changes(): void
    {
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
            'location_id' => $this->location->id, 'order_date' => today()->toDateString(),
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '50', 'unit_price' => '200']]], (string) Str::uuid7());
        $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
        $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items->first()->id, 'quantity' => '50', 'unit_cost' => '200']]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt->refresh(), $receipts->revision($receipt));
        $before = $this->snapshot();
        $data = app(VariantInventoryValuation::class)->read($this->actor, $this->variant, $this->location->id);
        $this->assertSame('200.000000', $data['latest_purchase']->purchase_unit_cost);
        $this->assertSame('200.000000', $data['state']->avg_cost);
        $this->assertSame('10000.0000', $data['state']->total_value);
        $this->assertSame('2000.0000', $this->variant->fresh()->cost_price);
        $this->assertSame('2500.0000', $this->variant->fresh()->sale_price);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('Latest posted purchase unit cost')->assertSee($receipt->receipt_number);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_english_and_arabic_preserve_the_existing_latin_digit_convention(): void
    {
        $this->stock($this->location, '12.5', '200', '2500');
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->withSession(['locale' => $locale]);
            Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee(__('Inventory Valuation'))->assertSee('12.5')->assertSee('EGP 200.00')->assertDontSee('12.5000');
        }
    }

    public function test_product_and_variant_creation_editing_and_price_inputs_still_work(): void
    {
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Valuation UI regression product', 'status' => 'draft', 'is_active' => false])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Valuation UI regression product')->firstOrFail();
        Livewire::test(EditProduct::class, ['record' => $product->slug])->fillForm(['name' => 'Updated valuation product'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated valuation product', $product->fresh()->name);
        $manager = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $manager->callAction(TestAction::make('create')->table(), ['sale_price' => '2500.1234', 'cost_price' => '2000.4321', 'is_active' => true])
            ->assertHasNoActionErrors();
        $variant = $product->variants()->firstOrFail();
        $this->assertSame('2500.1234', $variant->sale_price);
        $this->assertSame('2000.4321', $variant->cost_price);
        $manager->callAction(TestAction::make('edit')->table($variant), ['sale_price' => '2501.1234', 'cost_price' => '2001.4321', 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame('2501.1234', $variant->fresh()->sale_price);
        $this->assertSame('2001.4321', $variant->fresh()->cost_price);
    }

    public function test_no_active_locations_has_an_empty_state_and_disabled_inventory_denies_admin(): void
    {
        Module::where('code', 'inventory')->update(['is_active' => false]);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertForbidden();
        Module::where('code', 'inventory')->update(['is_active' => true]);
        Location::query()->update(['is_active' => false]);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())
            ->assertSee('No authorized inventory locations are available.')->assertDontSee('EGP 2,000.00');
    }

    public function test_standard_missing_revision_and_identical_ties_use_receipt_selection_rules(): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => 'standard']);
        $master = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '999']);
        Livewire::test(VariantInventoryValuationPanel::class, $this->panel())->assertSee('No effective standard-cost revision')->assertDontSee('EGP 999.00');
        $effective = now()->subHour();
        InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '200', 'effective_at' => $effective]);
        $latest = InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $master->id, 'new_cost' => '200', 'effective_at' => $effective]);
        $data = app(VariantInventoryValuation::class)->read($this->actor, $this->variant, $this->location->id);
        $this->assertSame($latest->id, $data['standard_revision']->id);
        $this->assertFalse($data['standard_problem']);
    }
}
