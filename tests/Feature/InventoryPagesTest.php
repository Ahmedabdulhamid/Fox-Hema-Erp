<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Filament\Resources\StockMovements\StockMovementResource;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\InventoryTestSetup;
use Tests\TestCase;

class InventoryPagesTest extends TestCase
{
    use InventoryTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function foreignStock(): array
    {
        $location = Location::create(['name' => 'Other branch', 'type' => 'branch']);
        $balance = StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $location->id, 'quantity_on_hand' => '9', 'quantity_available' => '9']);
        $movement = StockMovement::create(['variant_id' => $this->variant->id, 'location_id' => $location->id, 'type' => 'purchase', 'quantity' => '9']);

        return [$location, $balance, $movement];
    }

    public function test_both_lists_load_are_readonly_and_isolate_foreign_locations(): void
    {
        $movement = $this->postStock('3', 'receive');
        [$foreign, $balance, $foreignMovement] = $this->foreignStock();
        Livewire::test(ListStockBalances::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$this->balance()])->assertCanNotSeeTableRecords([$balance])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
        Livewire::test(ListStockMovements::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$movement])->assertCanNotSeeTableRecords([$foreignMovement])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
        $this->assertSame(['index'], array_keys(StockBalanceResource::getPages()));
        $this->assertSame(['index'], array_keys(StockMovementResource::getPages()));
        foreach ([$this->balance(), $movement] as $record) {
            $this->assertFalse(Gate::allows('update', $record));
            $this->assertFalse(Gate::allows('delete', $record));
        }
        $this->assertFalse(Gate::allows('view', $balance));
        $this->assertFalse(Gate::allows('view', $foreignMovement));
        $this->assertSame([$this->location->id], app(InventoryAccess::class)->locations($this->actor, InventoryPermissions::BALANCES)->pluck('id')->all());
        Livewire::test(ListStockBalances::class)->filterTable('location_id', $foreign->id)->assertCountTableRecords(0);
        Livewire::test(ListStockMovements::class)->filterTable('location_id', $foreign->id)->assertCountTableRecords(0);
    }

    public function test_search_and_product_location_type_date_filters(): void
    {
        $this->variant->product->update(['name' => 'Distinct inventory product']);
        $first = $this->postStock('3', 'receive');
        $second = $this->postStock('-1', 'sale', StockMovementType::Sale);
        $balance = $this->balance();
        foreach (['Distinct inventory product', $this->variant->sku, $this->variant->barcode] as $search) {
            Livewire::test(ListStockBalances::class)->searchTable($search)->assertCanSeeTableRecords([$balance]);
        }
        Livewire::test(ListStockBalances::class)->searchTable('not-a-product')->assertCountTableRecords(0);
        Livewire::test(ListStockBalances::class)->filterTable('product', $this->variant->product_id)->assertCanSeeTableRecords([$balance]);
        Livewire::test(ListStockMovements::class)->searchTable('Distinct inventory product')->assertCanSeeTableRecords([$first, $second]);
        Livewire::test(ListStockMovements::class)->searchTable($this->variant->sku)->assertCanSeeTableRecords([$first, $second]);
        Livewire::test(ListStockMovements::class)->filterTable('type', 'sale')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
        Livewire::test(ListStockMovements::class)->filterTable('created_at', ['from' => now()->addDay()->toDateString()])->assertCountTableRecords(0);
        Livewire::test(ListStockMovements::class)->filterTable('created_at', ['from' => now()->toDateString(), 'until' => now()->toDateString()])->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_cost_is_not_loaded_or_rendered_without_permission(): void
    {
        $movement = app(InventoryPostingService::class)->post($this->command('3', 'receive', unitCost: '876.5432'), $this->actor);
        $role = $this->actor->roles->first();
        $role->revokePermissionTo(InventoryPermissions::COST);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertNull(StockMovementResource::getEloquentQuery()->find($movement->id)->unit_cost);
        $this->assertArrayNotHasKey('cost_price', StockMovementResource::getEloquentQuery()->find($movement->id)->variant->getAttributes());
        Livewire::test(ListStockMovements::class)->assertSuccessful()->assertDontSee('876.5432');
    }

    public function test_module_permissions_navigation_and_direct_urls(): void
    {
        $this->assertTrue(StockBalanceResource::canAccess());
        $this->assertTrue(StockMovementResource::canAccess());
        $this->get(StockBalanceResource::getUrl())->assertSuccessful();
        $this->get(StockMovementResource::getUrl())->assertSuccessful();
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        $this->actor->roles->first()->revokePermissionTo([InventoryPermissions::BALANCES, InventoryPermissions::MOVEMENTS]);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse(StockBalanceResource::canAccess());
        $this->assertFalse(StockMovementResource::canAccess());
        $this->assertSame([], StockBalanceResource::getNavigationItems());
        $this->get(StockBalanceResource::getUrl())->assertForbidden();
    }

    public function test_super_admin_sees_all_locations_and_inactive_module_denies_even_admin(): void
    {
        $this->postStock('3', 'receive');
        [, $balance, $movement] = $this->foreignStock();
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        Livewire::test(ListStockBalances::class)->assertCanSeeTableRecords([$this->balance(), $balance]);
        Livewire::test(ListStockMovements::class)->assertCanSeeTableRecords([$movement]);
        Module::where('code', 'inventory')->update(['is_active' => false]);
        $this->assertFalse(StockBalanceResource::canAccess());
        $this->assertSame([], StockMovementResource::getNavigationItems());
        $this->get(StockMovementResource::getUrl())->assertForbidden();
        $this->assertSame(0, StockBalanceResource::getEloquentQuery()->count());
    }
}
