<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Filament\Resources\StockBalances\StockBalanceResource;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use DOMDocument;
use DOMXPath;
use Filament\Navigation\NavigationManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class NavbarLocationScopeTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    private function stock(Location $location, string $name): StockBalance
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['name' => $name])->id]);

        return StockBalance::create(['variant_id' => $variant->id, 'location_id' => $location->id, 'quantity_on_hand' => '7', 'quantity_available' => '7']);
    }

    public function test_localized_navbar_has_no_scope_selector_and_preserves_selected_authorized_location(): void
    {
        $second = Location::create(['name' => 'Second assigned warehouse', 'type' => 'warehouse']);
        $this->actor->locations()->attach($second);
        app(LocationContext::class)->setFor($this->actor, $second->id);
        $role = Role::create(['name' => 'second-location-role', 'guard_name' => 'web', 'location_id' => $second->id]);
        $role->givePermissionTo(InventoryPermissions::all());
        $this->actor->assignRole($role);
        $this->stock($this->location, 'FIRST-LOCATION-PRODUCT');
        $this->stock($second, 'SELECTED-LOCATION-PRODUCT');
        $foreign = Location::create(['name' => 'Unassigned warehouse', 'type' => 'warehouse']);
        $this->stock($foreign, 'UNAUTHORIZED-LOCATION-PRODUCT');
        $switches = DB::table('auth_events')->where('event', 'location_switched')->count();

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            Livewire::flushState();
            app()->forgetInstance(NavigationManager::class);
            $response = $this->withSession(['locale' => $locale, LocationContext::SESSION_KEY => $second->id])->get(StockBalanceResource::getUrl());

            $response->assertOk()->assertSessionHas(LocationContext::SESSION_KEY, $second->id)
                ->assertSee('SELECTED-LOCATION-PRODUCT')->assertDontSee('FIRST-LOCATION-PRODUCT')->assertDontSee('UNAUTHORIZED-LOCATION-PRODUCT')
                ->assertSee('dir="'.$direction.'"', false)->assertSee('fox-language-switcher', false)->assertSee('fox-topbar-identity', false)
                ->assertDontSee('fox-location-switcher', false)->assertDontSee('id="current-location"', false);
            $document = new DOMDocument;
            @$document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            $topbars = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-topbar ")]');
            $this->assertSame(1, $topbars->length);
            $this->assertSame(0, $xpath->query('.//select[@name="location_id"]', $topbars->item(0))->length);
            $this->assertStringNotContainsString(__('Global administration scope'), $topbars->item(0)->textContent);
        }
        $this->assertSame($switches, DB::table('auth_events')->where('event', 'location_switched')->count());
    }

    public function test_global_administrator_keeps_global_context_without_a_navbar_selector(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);

        $this->get(StockBalanceResource::getUrl())->assertOk()->assertDontSee('fox-location-switcher', false);
        $this->assertNull(session()->get(LocationContext::SESSION_KEY));
        $this->assertTrue($admin->isGlobalSuperAdmin());
    }

    public function test_global_administrator_keeps_an_explicitly_selected_context(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, $this->location->id);

        $this->get(StockBalanceResource::getUrl())->assertOk()->assertSessionHas(LocationContext::SESSION_KEY, $this->location->id);
    }

    public function test_backend_still_rejects_unauthorized_location_switching_without_changing_current_context(): void
    {
        $foreign = Location::create(['name' => 'Unauthorized branch', 'type' => 'branch']);

        $this->post(route('access-management.location.update'), ['location_id' => $foreign->id])
            ->assertForbidden()->assertSessionHas(LocationContext::SESSION_KEY, $this->location->id);
        $this->post(route('access-management.location.update'), ['location_id' => 'global'])
            ->assertForbidden()->assertSessionHas(LocationContext::SESSION_KEY, $this->location->id);
        $this->assertSame(0, DB::table('auth_events')->where('event', 'location_switched')->count());
    }

    public function test_invalid_session_context_is_sanitized_without_exposing_foreign_stock(): void
    {
        $foreign = Location::create(['name' => 'Unauthorized warehouse', 'type' => 'warehouse']);
        $this->stock($this->location, 'AUTHORIZED-STOCK-RECORD');
        $this->stock($foreign, 'SECRET-STOCK-RECORD');

        $this->withSession([LocationContext::SESSION_KEY => $foreign->id])->get(StockBalanceResource::getUrl())
            ->assertOk()->assertSessionHas(LocationContext::SESSION_KEY, $this->location->id)
            ->assertSee('AUTHORIZED-STOCK-RECORD')->assertDontSee('SECRET-STOCK-RECORD');
    }

    public function test_inventory_filters_still_restrict_data_without_changing_the_active_context(): void
    {
        $foreign = Location::create(['name' => 'Another warehouse', 'type' => 'warehouse']);
        $own = $this->stock($this->location, 'OWN-FILTER-STOCK');
        $other = $this->stock($foreign, 'OTHER-FILTER-STOCK');

        Livewire::withQueryParams(['stock-view' => 'records'])->test(ListStockBalances::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other])
            ->filterTable('location_id', $foreign->id)->assertCountTableRecords(0);
        $this->assertSame($this->location->id, session()->get(LocationContext::SESSION_KEY));

        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        Livewire::withQueryParams(['stock-view' => 'records'])->test(ListStockBalances::class)->filterTable('location_id', $foreign->id)
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$own]);
        $this->assertNull(session()->get(LocationContext::SESSION_KEY));
    }
}
