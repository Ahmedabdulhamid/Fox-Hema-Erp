<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ListSizeAllocations;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Livewire\AllocationStockTable;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\AllocationStockStatus;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SizeAllocationWorkspaceTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function pool(): ProductVariant
    {
        $this->variant->product->update(['name' => 'Workspace trainer']);
        $this->variant->update(['size_id' => Size::factory()->create(['name' => 'Workspace size'])->id, 'color_id' => Color::factory()->create(['name' => 'Workspace blue'])->id]);
        $source = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'unallocated_product_id' => $this->variant->product_id, 'size_id' => null, 'color_id' => null]);
        StockBalance::create(['variant_id' => $source->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '10', 'quantity_available' => '10']);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '4', 'quantity_reserved' => '1', 'quantity_available' => '3']);

        return app(AllocationStockStatus::class)->query($this->actor)->firstOrFail();
    }

    public function test_management_is_default_and_history_is_rendered_only_when_selected(): void
    {
        $pool = $this->pool();
        $document = SizeAllocation::factory()->create(['source_variant_id' => $pool->id, 'product_id' => $pool->product_id, 'location_id' => $this->location->id, 'created_by' => $this->actor->id]);
        $page = Livewire::test(ListSizeAllocations::class)->assertSet('workspaceTab', 'management')
            ->assertSeeLivewire(AllocationStockTable::class)->assertDontSee($document->reference)
            ->assertDontSee('fox-inventory-charts', false)->assertDontSee('fox-inventory-matrix', false);
        $page->call('switchWorkspace', 'history')->assertSet('workspaceTab', 'history')
            ->assertDontSeeLivewire(AllocationStockTable::class)->assertSee($document->reference)
            ->assertSee(__('Document status'))->filterTable('product_id', $pool->product_id);
        $page->call('switchWorkspace', 'management')->assertSeeLivewire(AllocationStockTable::class)
            ->assertDontSee($document->reference)->call('switchWorkspace', 'history')
            ->assertSet('tableFilters.product_id.value', $pool->product_id)->assertCanSeeTableRecords([$document]);
    }

    public function test_status_cards_search_reset_and_remount_preserve_the_management_contract(): void
    {
        $this->pool();
        $page = Livewire::test(AllocationStockTable::class)->assertCountTableRecords(1)->assertSet('tableFilters.allocation_state.value', 'needs');
        $this->assertSame(1, $page->instance()->counts()['review']);
        $page->call('chooseStatus', 'full')->assertCountTableRecords(1)
            ->assertSet('tableFilters.allocation_state.value', 'needs')
            ->assertSet('tableDeferredFilters.allocation_state.value', 'full');
        $this->assertSame(1, $page->instance()->counts()['needs']);
        $page->call('applyTableFilters')->assertCountTableRecords(0)
            ->assertSet('tableFilters.allocation_state.value', 'full');
        Livewire::test(AllocationStockTable::class)->assertSet('tableFilters.allocation_state.value', 'full');
        $page->call('chooseStatus', 'review')->assertCountTableRecords(0)
            ->assertSet('tableFilters.allocation_state.value', 'full')
            ->call('applyTableFilters')->searchTable('Workspace trainer')->assertCountTableRecords(1)
            ->searchTable('no matching product')->assertCountTableRecords(0);
        $this->assertSame(0, $page->instance()->counts()['needs']);
        $page->searchTable('')->filterTable('product_id', $this->variant->product_id)
            ->call('resetTableFiltersForm')->assertSet('tableFilters.allocation_state.value', 'needs')
            ->assertSet('tableFilters.product_id.value', null)->assertCountTableRecords(1);
        $html = $page->html();
        $this->assertStringNotContainsString('<select name="allocation_state"', $html);
    }

    public function test_details_load_only_the_selected_location_matrix_without_mutating_stock(): void
    {
        $pool = $this->pool();
        $foreign = Location::create(['name' => 'Private foreign warehouse', 'type' => 'warehouse']);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'quantity_on_hand' => '999', 'quantity_available' => '999']);
        $before = DB::table('stock_balances')->orderBy('id')->get()->toJson();
        $page = Livewire::test(AllocationStockTable::class)->assertDontSee('Workspace size')->assertDontSee('Workspace blue');
        $this->assertNotNull($page->instance()->getTableRecord($pool->id.'-'.$this->location->id));
        $page->mountTableAction('poolDetails', $pool)->assertSet('mountedActions.0.name', 'poolDetails')->assertSuccessful();
        $page->assertMountedActionModalSee('Workspace size')->assertMountedActionModalSee('Workspace blue')
            ->assertMountedActionModalSee($this->variant->sku)->assertMountedActionModalDontSee('Private foreign warehouse');
        $this->assertNull($page->instance()->getTableRecord($pool->id.'-'.$foreign->id));
        $page->unmountTableAction()->callTableColumnAction('product.name', $pool)->assertSet('mountedActions.0.name', 'poolDetails');
        $this->assertSame($before, DB::table('stock_balances')->orderBy('id')->get()->toJson());
    }

    public function test_history_remains_available_without_balance_or_management_permissions(): void
    {
        $this->actor->roles->first()->revokePermissionTo([InventoryPermissions::BALANCES, InventoryPermissions::ALLOCATIONS_MANAGE]);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(ListSizeAllocations::class)->assertActionHidden('create')
            ->assertSee(__('Stock balance access required'))->assertDontSeeLivewire(AllocationStockTable::class)
            ->call('switchWorkspace', 'history')->assertSee(__('Document status'));
        Livewire::test(AllocationStockTable::class)->assertForbidden();
    }

    public function test_localized_pages_and_history_deep_links_keep_the_requested_direction_and_tab(): void
    {
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $this->withSession(['locale' => $locale])->get(SizeAllocationResource::getUrl())
                ->assertOk()->assertSee('dir="'.$direction.'"', false)->assertSee(__('Size Allocations'));
        }

        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        Livewire::withQueryParams(['workspace' => 'history'])->test(ListSizeAllocations::class)
            ->assertSet('workspaceTab', 'history')->assertDontSeeLivewire(AllocationStockTable::class)->assertSee(__('Document status'));
    }
}
