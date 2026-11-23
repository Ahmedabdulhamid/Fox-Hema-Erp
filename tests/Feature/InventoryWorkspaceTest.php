<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\Pages\ListQuarantineReceipts;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\QuarantineReceiptResource;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ListSizeAllocations;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementPresentation;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryWorkspace;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InventoryTestSetup;
use Tests\TestCase;

class InventoryWorkspaceTest extends TestCase
{
    use InventoryTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->variant->product->update(['name' => 'Fox everyday trainer', 'brand_id' => Brand::factory()->create(['name' => 'Fox Studio'])->id]);
        $this->variant->update(['sku' => 'FOX-TRN-M', 'size_id' => Size::factory()->create(['name' => 'M / Regular', 'sort_order' => 1])->id]);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '4', 'quantity_reserved' => '2', 'quantity_available' => '2']);
    }

    public function test_balance_totals_cover_all_filtered_records_independently_of_pagination_and_search(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $variant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '2', 'quantity_available' => '2']);
        }
        $page = Livewire::test(ListStockBalances::class)->assertSuccessful()->assertCountTableRecords(31);
        $this->assertSame(['31', '64', '2', '62'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $page->set('tableRecordsPerPage', 10);
        $this->assertSame(['31', '64', '2', '62'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $page->searchTable('no matching product');
        $this->assertSame(['0', '0', '0', '0'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
    }

    public function test_cascading_filters_reset_children_and_scoped_search_options_exclude_foreign_stock(): void
    {
        $foreign = Location::create(['name' => 'Hidden warehouse', 'type' => 'warehouse']);
        $secret = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['name' => 'Hidden product', 'brand_id' => Brand::factory()->create(['name' => 'Hidden brand'])->id])->id]);
        $foreignBalance = StockBalance::create(['variant_id' => $secret->id, 'location_id' => $foreign->id, 'quantity_on_hand' => '99', 'quantity_available' => '99']);
        $before = DB::table('stock_balances')->orderBy('id')->get()->toJson();
        $service = app(InventoryWorkspace::class);
        $this->assertSame([$this->variant->product->brand_id => 'Fox Studio'], $service->options($this->actor, InventoryPermissions::BALANCES, 'balances', 'brand', []));
        $this->assertSame([], $service->options($this->actor, InventoryPermissions::BALANCES, 'balances', 'product', [], 'Hidden'));
        $this->assertSame([], $service->options($this->actor, InventoryPermissions::BALANCES, 'balances', 'variant', [], selected: $secret->id));
        $page = Livewire::test(ListStockBalances::class)->call('switchStockView', 'records')
            ->set('tableDeferredFilters.product.value', $this->variant->product_id)
            ->set('tableDeferredFilters.variant.value', $this->variant->id)->call('applyTableFilters')
            ->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->balance()])->assertCanNotSeeTableRecords([$foreignBalance]);
        $this->assertTrue($page->instance()->getTable()->hasDeferredFilters());
        $page->set('tableDeferredFilters.brand.value', $this->variant->product->brand_id)
            ->assertSet('tableDeferredFilters.product.value', null)->assertSet('tableDeferredFilters.variant.value', null)
            ->assertSet('tableFilters.product.value', $this->variant->product_id)->assertSet('tableFilters.variant.value', $this->variant->id)
            ->call('applyTableFilters')->assertSet('tableFilters.product.value', null)->assertSet('tableFilters.variant.value', null)
            ->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->balance()]);
        $page->set('tableDeferredFilters.product.value', $this->variant->product_id)
            ->set('tableDeferredFilters.variant.value', $this->variant->id)->call('applyTableFilters')
            ->set('tableDeferredFilters.location_id.value', $this->location->id)
            ->assertSet('tableDeferredFilters.brand.value', null)->assertSet('tableDeferredFilters.product.value', null)->assertSet('tableDeferredFilters.variant.value', null)
            ->assertSet('tableFilters.product.value', $this->variant->product_id)->call('applyTableFilters')
            ->assertSet('tableFilters.brand.value', null)->assertSet('tableFilters.product.value', null)->assertSet('tableFilters.variant.value', null);
        $page->set('tableDeferredFilters.location_id.value', $foreign->id)->assertCountTableRecords(1)
            ->assertCanSeeTableRecords([$this->balance()])->call('applyTableFilters')
            ->assertCountTableRecords(0)->assertCanNotSeeTableRecords([$foreignBalance]);
        $this->assertSame(['0', '0', '0', '0'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $page->set('tableDeferredFilters.location_id.value', $this->location->id)->set('tableDeferredFilters.product.value', $secret->product_id)
            ->call('applyTableFilters')->assertCountTableRecords(0)->assertCanNotSeeTableRecords([$foreignBalance]);
        $page->set('tableDeferredFilters.brand.value', $this->variant->product->brand_id)
            ->set('tableDeferredFilters.product.value', $this->variant->product_id)->set('tableDeferredFilters.variant.value', $this->variant->id)
            ->call('applyTableFilters')->call('removeTableFilter', 'brand')
            ->assertSet('tableFilters.product.value', null)->assertSet('tableFilters.variant.value', null)
            ->assertSet('tableDeferredFilters.product.value', null)->assertSet('tableDeferredFilters.variant.value', null)
            ->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->balance()]);
        $this->assertSame($before, DB::table('stock_balances')->orderBy('id')->get()->toJson());
    }

    public function test_movement_totals_preserve_signs_and_compose_with_type_date_and_reference_filters(): void
    {
        $in = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '8.2500', 'created_at' => now()->subDay()]);
        $out = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'type' => 'sale', 'quantity' => '-3.1250']);
        $foreign = Location::create(['name' => 'Hidden movement warehouse', 'type' => 'warehouse']);
        $foreignMovement = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'type' => 'purchase', 'quantity' => '99']);
        $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
        $page = Livewire::test(ListStockMovements::class)->assertSuccessful()->assertSet('stockView', 'products');
        $this->assertSame(['2', '8.25', '3.125', '5.125'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $groups = $page->instance()->movementProducts();
        $this->assertSame(1, $groups->total());
        $this->assertSame($this->variant->product_id, (int) $groups->first()->product_id);
        $this->assertSame($this->location->id, (int) $groups->first()->location_id);
        $this->assertSame(2, (int) $groups->first()->movement_count);
        $this->assertSame(8.25, (float) $groups->first()->incoming);
        $this->assertSame(3.125, (float) $groups->first()->outgoing);
        $page->call('mountAction', 'movementHistory', ['product' => $this->variant->product_id, 'location' => $this->location->id])
            ->assertMountedActionModalSee($this->variant->product->name)->assertMountedActionModalSee($this->location->name)
            ->assertMountedActionModalSeeHtml('wire:key="history-movement-'.$in->id.'"')
            ->assertMountedActionModalSeeHtml('wire:key="history-movement-'.$out->id.'"')
            ->assertMountedActionModalDontSeeHtml('wire:key="history-movement-'.$foreignMovement->id.'"')->call('unmountAction');
        $this->rejectInventory(fn () => app(InventoryMovementPresentation::class)->history($page->instance()->getFilteredTableQuery(), $this->actor, $this->variant->product_id, $foreign->id), HttpException::class);
        $page->set('tableDeferredFilters.type.value', 'sale');
        $this->assertSame(['2', '8.25', '3.125', '5.125'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $page->call('applyTableFilters');
        $this->assertSame(['1', '0', '3.125', '-3.125'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $this->assertSame(1, $page->instance()->movementProducts()->total());
        $this->assertSame(1, (int) $page->instance()->movementProducts()->first()->movement_count);
        $this->assertSame(0.0, (float) $page->instance()->movementProducts()->first()->incoming);
        $this->assertSame(3.125, (float) $page->instance()->movementProducts()->first()->outgoing);
        $page->call('mountAction', 'movementHistory', ['product' => $this->variant->product_id, 'location' => $this->location->id])
            ->assertMountedActionModalSeeHtml('wire:key="history-movement-'.$out->id.'"')
            ->assertMountedActionModalDontSeeHtml('wire:key="history-movement-'.$in->id.'"')
            ->assertMountedActionModalDontSeeHtml('wire:key="history-movement-'.$foreignMovement->id.'"')
            ->assertMountedActionModalSee('-3.125')->call('unmountAction');
        $page->set('tableDeferredFilters.created_at.from', today()->addDay()->toDateString());
        $this->assertSame(1, $page->instance()->movementProducts()->total());
        $page->call('applyTableFilters');
        $this->assertSame(0, $page->instance()->movementProducts()->total());
        $this->assertSame(['0', '0', '0', '0'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $page->call('resetTableFiltersForm')->call('applyTableFilters');
        $this->assertSame(1, $page->instance()->movementProducts()->total());
        $page->set('tableDeferredFilters.reference.number', 'DOES-NOT-EXIST');
        $this->assertSame(1, $page->instance()->movementProducts()->total());
        $page->call('applyTableFilters');
        $this->assertSame(0, $page->instance()->movementProducts()->total());
        $this->assertSame(['0', '0', '0', '0'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
        $this->assertSame($before, DB::table('stock_movements')->orderBy('id')->get()->toJson());
    }

    public function test_size_matrix_uses_configured_labels_actual_reservations_and_is_read_only(): void
    {
        $foreign = Location::create(['name' => 'Not authorized', 'type' => 'warehouse']);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'quantity_on_hand' => '999', 'quantity_available' => '999']);
        $before = DB::table('stock_balances')->orderBy('id')->get()->toJson();
        Livewire::test(ListSizeAllocations::class)->assertSuccessful()->assertDontSee('M / Regular');
        $matrix = app(InventoryWorkspace::class)->matrix($this->actor, []);
        $this->assertTrue($matrix['authorized']);
        $this->assertSame(['on_hand' => '4.0000', 'reserved' => '2.0000', 'available' => '2.0000'], $matrix['totals']);
        $this->assertSame('M / Regular', $matrix['sizes'][0]['label']);
        $this->assertSame($before, DB::table('stock_balances')->orderBy('id')->get()->toJson());
        $this->assertSame([], app(InventoryWorkspace::class)->matrix($this->actor, ['location_id' => ['value' => $foreign->id]])['sizes']);
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::BALANCES);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(ListSizeAllocations::class)->assertSee(__('Stock balance access required'));
        $this->assertFalse(app(InventoryWorkspace::class)->matrix($this->actor, [])['authorized']);
    }

    public function test_empty_quarantine_has_only_supported_metrics_and_no_release_actions(): void
    {
        $page = Livewire::test(ListQuarantineReceipts::class)->assertSuccessful()->assertCountTableRecords(0)
            ->assertTableActionDoesNotExist('release')->assertTableActionDoesNotExist('delete')
            ->assertSee(__('Observation only: quarantined stock remains separate until an authorized disposition is supported.'));
        $this->assertSame(['0', '0', '0', '0'], array_column($page->instance()->inventoryOverview()['cards'], 'value'));
    }

    public function test_localized_full_pages_render_without_mutation_and_can_capture_fixture_previews(): void
    {
        foreach (['S', 'L', 'XL', '2XL', '3XL'] as $index => $label) {
            $variant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id,
                'size_id' => Size::factory()->create(['name' => $label, 'sort_order' => $index + 2])->id, 'sku' => 'FOX-TRN-'.($index + 1)]);
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id,
                'quantity_on_hand' => (string) (($index + 1) * 8), 'quantity_reserved' => (string) $index, 'quantity_available' => (string) (($index + 1) * 8 - $index)]);
            StockMovement::create(['variant_id' => $variant->id, 'location_id' => $this->location->id,
                'type' => ['purchase', 'sale', 'return', 'transfer_in', 'adjustment'][$index],
                'quantity' => $index === 1 ? '-2' : '8', 'created_by' => $this->actor->id, 'notes' => 'Isolated inventory test fixture']);
        }
        $draft = SizeAllocation::factory()->create(['product_id' => $this->variant->product_id, 'location_id' => $this->location->id, 'created_by' => $this->actor->id]);
        SizeAllocationItem::factory()->create(['allocation_id' => $draft->id, 'variant_id' => $this->variant->id, 'quantity' => '2']);
        $before = DB::table('stock_balances')->get()->toJson();
        $pages = ['balances' => StockBalanceResource::class, 'movements' => StockMovementResource::class, 'quarantine' => QuarantineReceiptResource::class, 'allocations' => SizeAllocationResource::class];
        foreach (['en', 'ar'] as $locale) {
            foreach ($pages as $key => $resource) {
                Livewire::flushState();
                app()->forgetInstance(NavigationManager::class);
                $response = $this->withSession(['locale' => $locale])->get($resource::getUrl());
                $response->assertOk()->assertSee('fox-inventory-kpis', false);
                $response->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
                if (getenv('INVENTORY_CAPTURE_PREVIEWS') === '1') {
                    $directory = storage_path('app/private/inventory-previews');
                    if (! is_dir($directory)) {
                        mkdir($directory, 0700, true);
                    }
                    file_put_contents($directory.'/'.$key.'-'.$locale.'.html', $response->getContent());
                }
            }
        }
        $this->assertSame($before, DB::table('stock_balances')->get()->toJson());
    }

    public function test_allocation_history_filters_remain_available_without_stock_balance_permission(): void
    {
        $draft = SizeAllocation::factory()->create(['product_id' => $this->variant->product_id, 'location_id' => $this->location->id, 'created_by' => $this->actor->id]);
        SizeAllocationItem::factory()->create(['allocation_id' => $draft->id, 'variant_id' => $this->variant->id]);
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::BALANCES);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $options = app(InventoryWorkspace::class)->options($this->actor, InventoryPermissions::ALLOCATIONS_VIEW, 'allocations', 'product_id', []);
        $this->assertSame([$this->variant->product_id => 'Fox everyday trainer'], $options);
        Livewire::test(ListSizeAllocations::class)->call('switchWorkspace', 'history')->filterTable('product_id', $this->variant->product_id)->assertCanSeeTableRecords([$draft])
            ->assertTableColumnStateSet('allocated_quantity', '1', $draft);
    }

    public function test_additional_table_rows_do_not_add_product_relationship_queries(): void
    {
        DB::enableQueryLog();
        Livewire::test(ListStockBalances::class)->assertSuccessful();
        $relationshipQueries = fn (): int => count(array_filter(DB::getQueryLog(), fn (array $query): bool => preg_match('/select .* from .?(products|brands|product_images|sizes|colors).? /i', $query['query']) === 1));
        $small = $relationshipQueries();
        DB::disableQueryLog();
        for ($i = 0; $i < 15; $i++) {
            $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['brand_id' => Brand::factory()->create()->id])->id]);
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '2', 'quantity_available' => '2']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListStockBalances::class)->assertSuccessful();
        $this->assertLessThanOrEqual($small + 2, $relationshipQueries());
        DB::disableQueryLog();
    }
}
