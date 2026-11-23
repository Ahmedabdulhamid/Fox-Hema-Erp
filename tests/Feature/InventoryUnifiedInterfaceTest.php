<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\Pages\ListQuarantineReceipts;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\Inventory\Services\InventoryMovementPresentation;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Brick\Math\BigDecimal;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class InventoryUnifiedInterfaceTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->freezeTime();
    }

    private function stock(ProductVariant $variant, string $quantity, string $reserved = '0', ?Location $location = null): StockBalance
    {
        return StockBalance::create(['variant_id' => $variant->id, 'location_id' => ($location ?? $this->location)->id,
            'quantity_on_hand' => $quantity, 'quantity_reserved' => $reserved,
            'quantity_available' => (string) BigDecimal::of($quantity)->minus($reserved)]);
    }

    private function unallocated(): ProductVariant
    {
        return ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'unallocated_product_id' => $this->variant->product_id,
            'size_id' => null, 'color_id' => null, 'sku' => 'SEPARATE-UNALLOCATED-STOCK']);
    }

    public function test_balance_products_group_variants_without_mixing_unallocated_quantities_or_losing_records(): void
    {
        $this->stock($this->variant, '8', '3');
        $other = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'size_id' => Size::factory()->create()->id]);
        $this->stock($other, '4', '1');
        $this->stock($this->unallocated(), '10', '2');
        $before = DB::table('stock_balances')->get()->toJson();
        $page = Livewire::test(ListStockBalances::class)->assertSet('stockView', 'products')->assertSee(__('Products by location'));
        $groups = $page->instance()->productGroups();
        $this->assertSame(1, $groups->total());
        $this->assertSame(12.0, (float) $groups->first()->quantity_on_hand);
        $this->assertSame(4.0, (float) $groups->first()->quantity_reserved);
        $this->assertSame(8.0, (float) $groups->first()->quantity_available);
        $this->assertSame(10.0, (float) $groups->first()->unallocated_quantity_on_hand);
        $this->assertSame(8.0, (float) $groups->first()->unallocated_quantity_available);
        $page->call('switchStockView', 'records')->assertCountTableRecords(3)->assertSee($this->variant->sku);
        $this->assertSame($before, DB::table('stock_balances')->get()->toJson());
    }

    public function test_table_filter_drafts_leave_records_and_kpis_unchanged_until_apply(): void
    {
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();
        $this->variant->product->update(['brand_id' => $brand->id]);
        $other = ProductVariant::factory()->create();
        $other->product->update(['brand_id' => $otherBrand->id]);
        $this->stock($this->variant, '5');
        $this->stock($other, '8');

        $page = Livewire::test(ListStockBalances::class)->call('switchStockView', 'records')->assertCountTableRecords(2);
        $this->assertTrue($page->instance()->getTable()->hasDeferredFilters());
        $this->assertSame(0, $page->instance()->getTable()->getActiveFiltersCount());

        $page->set('tableDeferredFilters.brand.value', (string) $brand->id);
        $this->assertSame(2, $page->instance()->productGroups()->total());
        $this->assertSame(2.0, (float) $page->instance()->inventoryOverview()['cards'][0]['value']);
        $this->assertSame(0, $page->instance()->getTable()->getActiveFiltersCount());

        $page->call('setPage', 2)->call('applyTableFilters')->assertCountTableRecords(1);
        $this->assertSame(1, $page->instance()->productGroups()->total());
        $this->assertSame(1.0, (float) $page->instance()->inventoryOverview()['cards'][0]['value']);
        $this->assertSame(1, $page->instance()->getTable()->getActiveFiltersCount());
        $this->assertSame(1, $page->instance()->getPage($page->instance()->getTablePaginationPageName()));

        $quarantine = Livewire::test(ListQuarantineReceipts::class);
        $movements = Livewire::test(ListStockMovements::class);
        $this->assertTrue($quarantine->instance()->getTable()->hasDeferredFilters());
        $this->assertTrue($movements->instance()->getTable()->hasDeferredFilters());
    }

    public function test_matrix_keeps_a_normal_size_less_variant_and_separates_the_unallocated_source(): void
    {
        $this->variant->update(['size_id' => null, 'color_id' => null]);
        $this->stock($this->variant, '8');
        $source = $this->unallocated();
        $this->stock($source, '10', '2');
        $matrix = app(InventoryInsights::class)->matrix($this->actor, $this->variant->product_id, $this->location->id, 'available');
        $this->assertSame([0 => __('No size')], $matrix['sizes']);
        $this->assertSame([$this->variant->id], collect($matrix['rows'][0]['cells'][0])->pluck('id')->all());
        $this->assertSame([$source->id], $matrix['unallocated']->pluck('id')->all());
        $this->assertSame(8.0, (float) $matrix['total']);
        $page = Livewire::withQueryParams(['location' => (string) $this->location->id])->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])
            ->assertSee($source->sku)->assertSee(__('Separate from the color and size matrix. Existing stock records are preserved.'));
        $document = new DOMDocument;
        @$document->loadHTML($page->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $matrixText = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fox-inventory-matrix ")]')->item(0)->textContent;
        $this->assertStringNotContainsString($source->sku, $matrixText);
    }

    public function test_overview_search_filters_and_reset_keep_product_grouping_and_exact_summary_totals(): void
    {
        $this->variant->product->update(['name' => 'Unified inventory product']);
        $this->stock($this->variant, '8', '3');
        $this->stock(ProductVariant::factory()->create(['product_id' => $this->variant->product_id]), '4', '1');
        $this->stock($this->unallocated(), '10', '2');
        $page = Livewire::test(InventoryOverview::class)->set('search', 'Unified inventory product');
        $data = $page->instance()->inventory();
        $this->assertSame(1, $data['rows']->total());
        $this->assertSame(22.0, (float) $data['summary']['on_hand']);
        $this->assertSame(12.0, (float) $data['rows']->first()->quantity_on_hand);
        $page->set('search', 'no match');
        $this->assertSame(0, $page->instance()->inventory()['rows']->total());
        $page->call('resetInventoryFilters')->assertSet('search', '')->assertSet('location', '')->assertSet('status', '');
        $this->assertSame(1, $page->instance()->inventory()['rows']->total());
    }

    public function test_shared_product_drawers_reject_foreign_locations_and_do_not_change_active_location(): void
    {
        $this->stock($this->variant, '8');
        $foreign = Location::create(['name' => 'Private warehouse', 'type' => 'warehouse']);
        $this->stock($this->variant, '999', location: $foreign);
        $page = Livewire::test(InventoryOverview::class)->mountAction('productDetails', ['product' => $this->variant->product_id, 'location' => $this->location->id])
            ->assertMountedActionModalSee($this->variant->product->name)->assertMountedActionModalDontSee('Private warehouse');
        $this->assertSame($this->location->id, session(LocationContext::SESSION_KEY));
        Livewire::test(InventoryOverview::class)->mountAction('productDetails', ['product' => $this->variant->product_id, 'location' => $foreign->id])->assertForbidden();
        $balances = Livewire::test(ListStockBalances::class)->filterTable('location_id', $foreign->id);
        $this->assertSame(0, $balances->instance()->productGroups()->total());
    }

    public function test_ledger_rows_remain_separate_with_signed_quantities_and_permission_aware_details(): void
    {
        $first = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '5', 'unit_cost' => '87654']);
        $second = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'sale', 'quantity' => '-2', 'unit_cost' => '87654']);
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::COST);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::withQueryParams(['stock-view' => 'records'])->test(ListStockMovements::class)->assertCountTableRecords(2)->assertCanSeeTableRecords([$first, $second])
            ->mountTableAction('movementDetails', $second)->assertMountedActionModalSee('-2')->assertMountedActionModalDontSee('87654');
        $this->assertDatabaseCount('stock_movements', 2);
    }

    private function custody(ProductVariant $variant, string $quantity): QuarantineReceipt
    {
        $order = SalesOrder::factory()->create(['location_id' => $this->location->id]);
        $item = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'variant_id' => $variant->id]);
        $deliveryId = DB::table('sales_deliveries')->insertGetId(SalesDelivery::factory()->raw(['uuid' => (string) Str::uuid7(), 'sales_order_id' => $order->id, 'location_id' => $this->location->id]));
        $deliveryItemId = DB::table('sales_delivery_items')->insertGetId(SalesDeliveryItem::factory()->raw(['sales_order_item_id' => $item->id, 'sales_order_id' => $order->id, 'sales_delivery_id' => $deliveryId]));
        $returnId = DB::table('sales_returns')->insertGetId(SalesReturn::factory()->raw(['uuid' => (string) Str::uuid7(), 'sales_order_id' => $order->id, 'location_id' => $this->location->id]));
        $returnItemId = DB::table('sales_return_items')->insertGetId(SalesReturnItem::factory()->raw(['sales_return_id' => $returnId, 'sales_delivery_item_id' => $deliveryItemId, 'variant_id' => $variant->id]));
        $cost = InventoryCostEntry::factory()->create();
        $allocation = DB::table('sales_return_cost_allocations')->insertGetId(['uuid' => (string) Str::uuid7(), 'slice_key' => 'test', 'operation_identity' => hash('sha256', (string) Str::uuid7()),
            'sales_return_id' => $returnId, 'sales_return_item_id' => $returnItemId, 'sales_delivery_item_id' => $deliveryItemId,
            'original_cost_entry_id' => $cost->id, 'location_id' => $this->location->id, 'variant_id' => $variant->id, 'method' => 'avco', 'disposition' => 'damaged',
            'quantity' => $quantity, 'original_unit_cost' => '1', 'original_cost' => $quantity, 'carrying_value' => $quantity, 'posted_by' => $this->actor->id, 'posted_at' => now()]);
        $receipt = DB::table('quarantine_receipts')->insertGetId(['uuid' => (string) Str::uuid7(), 'return_cost_allocation_id' => $allocation, 'location_id' => $this->location->id,
            'variant_id' => $variant->id, 'quantity' => $quantity, 'original_cost' => $quantity, 'provisional_carrying_value' => $quantity,
            'condition_notes' => 'Sealed custody evidence', 'responsible_user_id' => $this->actor->id, 'received_by' => $this->actor->id, 'received_at' => now()]);

        return QuarantineReceipt::findOrFail($receipt);
    }

    public function test_quarantine_groups_stock_but_keeps_every_custody_record_and_never_offers_allocation_or_release(): void
    {
        $first = $this->custody($this->variant, '3');
        $second = $this->custody($this->variant, '2');
        $this->custody($this->unallocated(), '4');
        $before = DB::table('quarantine_receipts')->get()->toJson();
        $page = Livewire::test(ListQuarantineReceipts::class)->assertSee(__('Quarantined; unavailable for allocation'))->assertDontSee(__('Allocate Now'));
        $groups = $page->instance()->productGroups();
        $this->assertSame(1, $groups->total());
        $this->assertSame(5.0, (float) $groups->first()->quantity_on_hand);
        $this->assertSame(4.0, (float) $groups->first()->unallocated_quantity_on_hand);
        $page->call('showProductRecords', $this->variant->product_id, $this->location->id)
            ->assertSet('stockView', 'records')
            ->assertSet('tableFilters.product.value', $this->variant->product_id)
            ->assertSet('tableDeferredFilters.product.value', $this->variant->product_id)
            ->assertCountTableRecords(3)
            ->assertCanSeeTableRecords([$first, $second])->assertTableActionDoesNotExist('release')
            ->mountTableAction('custodyDetails', $first)->assertMountedActionModalSee('Sealed custody evidence');
        $this->assertSame($before, DB::table('quarantine_receipts')->get()->toJson());
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_product_group_pagination_keeps_products_together_and_missing_balances_unknown(): void
    {
        for ($index = 0; $index < 27; $index++) {
            $this->stock(ProductVariant::factory()->create(), '2');
        }
        $page = Livewire::test(ListStockBalances::class);
        $groups = $page->instance()->productGroups();
        $this->assertSame(27, $groups->total());
        $this->assertCount(25, $groups->items());
        $page->call('setPage', 2);
        $this->assertCount(2, $page->instance()->productGroups()->items());
        $overview = Livewire::test(InventoryOverview::class)->set('product', (string) $this->variant->product_id);
        $row = $overview->instance()->inventory()['rows']->first();
        $this->assertNull($row->quantity_on_hand);
        $this->assertSame(1, (int) $row->missing_balances);
    }

    public function test_product_activity_is_loaded_only_when_requested(): void
    {
        $movement = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '3']);
        $page = Livewire::withQueryParams(['location' => (string) $this->location->id])->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid]);
        $this->assertCount(0, $page->instance()->details()['movements']);
        $page->set('showActivity', true);
        $this->assertSame([$movement->id], $page->instance()->details()['movements']->pluck('id')->all());
    }

    public function test_movement_products_group_variants_and_drawer_paginates_the_authorized_filtered_history(): void
    {
        $otherVariant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $unrelated = ProductVariant::factory()->create();
        for ($index = 0; $index < 28; $index++) {
            StockMovement::create(['variant_id' => $index % 2 ? $otherVariant->id : $this->variant->id,
                'location_id' => $this->location->id, 'type' => $index % 2 ? 'sale' : 'purchase', 'quantity' => $index % 2 ? '-1' : '3']);
        }
        StockMovement::create(['variant_id' => $unrelated->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '99']);
        $foreign = Location::create(['name' => 'Foreign movement scope', 'type' => 'branch']);
        StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'type' => 'purchase', 'quantity' => '900']);
        $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();

        $page = Livewire::test(ListStockMovements::class);
        $groups = $page->instance()->movementProducts();
        $this->assertSame(2, $groups->total());
        $group = $groups->firstWhere('product_id', $this->variant->product_id);
        $this->assertSame(2, (int) $group->variant_count);
        $this->assertSame(42.0, (float) $group->incoming);
        $this->assertSame(14.0, (float) $group->outgoing);
        $this->assertCount(2, $group->variants);

        $presenter = app(InventoryMovementPresentation::class);
        $query = $page->instance()->getFilteredTableQuery();
        $history = $presenter->history($query, $this->actor, $this->variant->product_id, $this->location->id);
        $this->assertSame(28, $history['movements']->total());
        $this->assertCount(25, $history['movements']->items());
        $this->assertSame(28.0, (float) $history['summary']->net);
        $this->assertNull($history['currentBalance']);
        $this->stock($this->variant, '80');
        $this->stock($otherVariant, '5');
        $current = $presenter->history($query, $this->actor, $this->variant->product_id, $this->location->id);
        $this->assertSame(85.0, (float) $current['currentBalance']);
        $this->assertSame(28.0, (float) $current['summary']->net);
        $this->assertCount(3, $presenter->history($query, $this->actor, $this->variant->product_id, $this->location->id, 2)['movements']->items());
        $page->call('mountAction', 'movementHistory', ['product' => $this->variant->product_id, 'location' => $this->location->id])
            ->assertMountedActionModalSee($this->variant->product->name)
            ->call('movementHistoryPage', 2)->assertSet('movementHistoryPage', 2);

        $page->set('tableDeferredFilters.type.value', 'sale');
        $this->assertSame(2, $page->instance()->movementProducts()->total());
        $page->call('unmountAction')->call('applyTableFilters');
        $this->assertSame(1, $page->instance()->movementProducts()->total());
        $filtered = $presenter->history($page->instance()->getFilteredTableQuery(), $this->actor, $this->variant->product_id, $this->location->id);
        $this->assertSame(14, $filtered['movements']->total());
        $this->assertSame(0.0, (float) $filtered['summary']->incoming);
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::BALANCES);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $restricted = $presenter->history($query, $this->actor, $this->variant->product_id, $this->location->id);
        $this->assertFalse($restricted['canViewBalance']);
        $this->assertNull($restricted['currentBalance']);
        $this->assertSame($before, DB::table('stock_movements')->orderBy('id')->get()->toJson());
    }

    public function test_movement_history_rejects_foreign_locations_and_products_outside_the_selected_scope(): void
    {
        $this->postStock('3', 'movement-drawer-scope');
        $foreign = Location::create(['name' => 'Not permitted', 'type' => 'branch']);
        $query = StockMovementResource::getEloquentQuery();
        $this->rejectInventory(fn () => app(InventoryMovementPresentation::class)
            ->history($query, $this->actor, $this->variant->product_id, $foreign->id), HttpException::class);
        $this->rejectInventory(fn () => app(InventoryMovementPresentation::class)
            ->history($query, $this->actor, ProductVariant::factory()->create()->product_id, $this->location->id), HttpException::class);
    }

    public function test_color_swatches_accept_stored_hex_and_reject_css_injection(): void
    {
        $this->assertStringContainsString('background-color: #FFFFFF', view('components.inventory-color', ['hex' => '#FFFFFF', 'name' => 'White'])->render());
        $invalid = view('components.inventory-color', ['hex' => 'red;background-image:url(https://invalid.example)', 'name' => '<script>bad</script>'])->render();
        $this->assertStringContainsString('background-color: #D1D5DB', $invalid);
        $this->assertStringNotContainsString('background-image', $invalid);
        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $invalid);
    }

    public function test_movement_product_groups_keep_locations_distinct_for_global_administrators(): void
    {
        $this->postStock('3', 'movement-location-summary');
        $foreign = Location::create(['name' => 'Independent warehouse', 'type' => 'warehouse']);
        StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'type' => 'purchase', 'quantity' => '17']);
        $admin = $this->provisionTestSuperAdmin();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        $groups = app(InventoryMovementPresentation::class)->products(StockMovementResource::getEloquentQuery(), $admin);
        $this->assertSame(2, $groups->total());
        $this->assertSame(3.0, (float) $groups->firstWhere('location_id', $this->location->id)->incoming);
        $this->assertSame(17.0, (float) $groups->firstWhere('location_id', $foreign->id)->incoming);
    }

    public function test_all_five_inventory_pages_render_in_arabic_and_english(): void
    {
        $this->postStock('5', 'inventory-ui-render');
        $this->custody($this->variant, '2');
        $pages = [
            'inventory-overview' => InventoryOverview::class,
            'stock-balances' => StockBalanceResource::class,
            'stock-movements' => StockMovementResource::class,
            'quarantine-receipts' => QuarantineReceiptResource::class,
            'size-allocations' => SizeAllocationResource::class,
        ];
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach ($pages as $slug => $page) {
                $response = $this->withSession(['locale' => $locale])->get($page::getUrl());
                $response->assertOk()->assertSee('dir="'.$direction.'"', false)->assertSee('fox-inventory-page', false);
                if (getenv('INVENTORY_CAPTURE_UI') === '1') {
                    $html = preg_replace('~https?://(?:localhost|127\.0\.0\.1)(?::\d+)?/~', 'http://127.0.0.1:8000/', $response->getContent());
                    file_put_contents(storage_path('logs/inventory-preview-'.$slug.'-'.$locale.'.html'), $html);
                }
            }
        }
    }
}
