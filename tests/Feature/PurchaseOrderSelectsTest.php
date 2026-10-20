<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\PurchasingTestSetup;
use Tests\TestCase;

class PurchaseOrderSelectsTest extends TestCase
{
    use PurchasingTestSetup;

    private Location $location;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'en']);
        app()->setLocale('en');
        $this->setUpPurchasingDatabase();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, null);
        $this->location = Location::create(['name' => 'Select warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create(['name' => 'Nile Supply', 'is_active' => true]);
    }

    private function select(Testable $page, string $name): Select
    {
        $field = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field instanceof Select && $field->getName() === $name);
        $this->assertInstanceOf(Select::class, $field);

        return $field;
    }

    private function variant(string $name, ?string $color = null, ?string $size = null): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['name' => $name, 'is_active' => true, 'status' => 'active'])->id,
            'color_id' => $color === null ? null : Color::factory()->create(['name' => $color])->id,
            'size_id' => $size === null ? null : Size::factory()->create(['name' => $size])->id,
        ]);
    }

    private function scoped(Location $location): User
    {
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $role = Role::create(['name' => 'po-selects-'.$actor->id, 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo([PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_CREATE, PurchasingPermissions::ORDERS_UPDATE]);
        $actor->assignRole($role);
        $this->actingAs($actor);

        return $actor;
    }

    public function test_active_suppliers_load_without_search_and_inactive_suppliers_are_excluded(): void
    {
        $inactive = Supplier::factory()->create(['name' => 'Inactive Supply', 'is_active' => false]);
        $field = $this->select(Livewire::test(CreatePurchaseOrder::class), 'supplier_id');
        $this->assertTrue($field->isPreloaded());
        $this->assertSame([$this->supplier->id => 'Nile Supply'], $field->getOptions());
        $this->assertSame([$this->supplier->id => 'Nile Supply'], $field->getSearchResults('Nile'));
        $this->assertArrayNotHasKey($inactive->id, $field->getSearchResults('Supply'));
        $this->assertSame('Search suppliers by name', $field->getSearchPrompt());
    }

    public function test_global_suppliers_are_available_in_both_authorized_location_teams(): void
    {
        $second = Location::create(['name' => 'Select branch', 'type' => 'branch', 'is_active' => true]);
        foreach ([$this->location, $second] as $location) {
            $this->scoped($location);
            $page = Livewire::test(CreatePurchaseOrder::class);
            $this->assertArrayHasKey($this->supplier->id, $this->select($page, 'supplier_id')->getOptions());
            $this->assertSame([$location->id => $location->name], $this->select($page, 'location_id')->getOptions());
        }
    }

    public function test_variant_options_use_real_attributes_and_support_missing_attributes(): void
    {
        foreach ([['Nike Air Max', 'Black', '42'], ['Jacket', 'White', null], ['Trousers', null, 'M'], ['Samsung Monitor', null, null]] as [$name, $color, $size]) {
            $variant = $this->variant($name, $color, $size);
            $page = Livewire::test(CreatePurchaseOrder::class)->fillForm(['items' => [[
                'product_id' => $variant->product_id, 'receiving_mode' => $size ? 'known' : 'standard', 'variant_id' => $variant->id,
            ]]]);
            $field = $this->select($page, 'variant_id');
            $this->assertTrue($field->isPreloaded());
            $this->assertSame([$variant->id => $variant->displayName()], $field->getOptions());
            foreach (array_filter([$name, $color, $size, $variant->sku]) as $search) {
                $this->assertArrayHasKey($variant->id, $field->getSearchResults($search));
            }
        }
    }

    public function test_ineligible_products_and_variants_are_excluded_from_initial_and_search_options(): void
    {
        $active = $this->variant('Active product');
        $inactive = $this->variant('Inactive variant');
        $inactive->update(['is_active' => false]);
        $draft = $this->variant('Draft product');
        $draft->product->update(['status' => 'draft']);
        $disabledProduct = $this->variant('Disabled product');
        $disabledProduct->product->update(['is_active' => false]);
        $field = $this->select(Livewire::test(CreatePurchaseOrder::class), 'product_id');
        $this->assertSame([$active->product_id => 'Active product'], $field->getOptions());
        foreach ([$inactive, $draft, $disabledProduct] as $variant) {
            $this->assertArrayNotHasKey($variant->product_id, $field->getSearchResults($variant->sku));
        }
    }

    public function test_saved_ids_and_labels_load_in_edit_including_historical_inactive_selections(): void
    {
        $variant = $this->variant('Nike Air Max', 'Black', '42');
        Livewire::test(CreatePurchaseOrder::class)->fillForm([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => '2026-10-14',
            'items' => [['product_id' => $variant->product_id, 'receiving_mode' => $variant->size_id ? 'known' : 'standard', 'variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '100', 'discount_amount' => '0', 'tax_amount' => '0']],
        ])->call('create')->assertHasNoFormErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->assertSame($this->supplier->id, $order->supplier_id);
        $this->assertSame($variant->id, $order->items()->first()->variant_id);
        $page = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid]);
        $this->assertSame('Nile Supply', $this->select($page, 'supplier_id')->getOptionLabel());
        $this->assertSame('Nike Air Max — Black / Size 42', $this->select($page, 'variant_id')->getOptionLabel());
        $page->fillForm(['notes' => 'Saved without changing selections'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($variant->id, $order->items()->first()->variant_id);
        $this->supplier->update(['is_active' => false]);
        $variant->update(['is_active' => false]);
        $reopened = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid]);
        $this->assertSame('Nile Supply', $this->select($reopened, 'supplier_id')->getOptionLabel());
        $this->assertSame('Nike Air Max — Black / Size 42', $this->select($reopened, 'variant_id')->getOptionLabel());
        $this->assertArrayNotHasKey($this->supplier->id, $this->select($reopened, 'supplier_id')->getOptions());
        $this->assertArrayNotHasKey($variant->id, $this->select($reopened, 'variant_id')->getOptions());
    }

    public function test_preload_is_bounded_and_selected_supplier_outside_preload_still_has_a_label(): void
    {
        Supplier::factory()->count(51)->create(['name' => 'A supplier']);
        $page = Livewire::test(CreatePurchaseOrder::class)->fillForm(['supplier_id' => $this->supplier->id]);
        $field = $this->select($page, 'supplier_id');
        $this->assertCount(50, $field->getOptions());
        $this->assertArrayNotHasKey($this->supplier->id, $field->getOptions());
        $this->assertSame('Nile Supply', $field->getOptionLabel());
        $this->assertSame([$this->supplier->id => 'Nile Supply'], $field->getSearchResults('Nile'));
    }

    public function test_arabic_labels_and_search_prompts_preserve_actual_product_attributes(): void
    {
        $variant = $this->variant('حذاء رياضي', 'أسود', '42');
        foreach (['en' => ['Product', 'Unknown sizes', 'Known sizes'], 'ar' => ['المنتج', 'مقاسات غير معروفة', 'مقاسات معروفة']] as $locale => [$product, $unknown, $known]) {
            app()->setLocale($locale);
            $page = Livewire::test(CreatePurchaseOrder::class)->fillForm(['items' => [['product_id' => $variant->product_id, 'receiving_mode' => 'known']]]);
            $this->assertSame($product, $this->select($page, 'product_id')->getLabel());
            $options = $this->select($page, 'receiving_mode')->getOptions();
            $this->assertSame($unknown, $options['unknown']);
            $this->assertSame($known, $options['known']);
            $this->assertSame($variant->displayName(), $this->select($page, 'variant_id')->getOptions()[$variant->id]);
        }
    }

    public function test_location_changes_and_inactive_supplier_submissions_are_rejected_server_side(): void
    {
        $variant = $this->variant('Eligible product');
        $other = Location::create(['name' => 'Unassigned branch', 'type' => 'branch', 'is_active' => true]);
        $this->scoped($this->location);
        $payload = ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => '2026-10-14',
            'items' => [['product_id' => $variant->product_id, 'receiving_mode' => $variant->size_id ? 'known' : 'standard', 'variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_amount' => '0']]];
        Livewire::test(CreatePurchaseOrder::class)->fillForm(array_replace($payload, ['location_id' => $other->id]))->call('create')->assertHasFormErrors(['location_id']);
        $page = Livewire::test(CreatePurchaseOrder::class)->fillForm($payload);
        $this->supplier->update(['is_active' => false]);
        $page->call('create')->assertHasFormErrors(['supplier_id']);
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_open_select_queries_stop_exposing_options_when_module_is_disabled(): void
    {
        $variant = $this->variant('Eligible product');
        $page = Livewire::test(CreatePurchaseOrder::class);
        $supplier = $this->select($page, 'supplier_id');
        $variants = $this->select($page, 'variant_id');
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->assertSame([], $supplier->getOptions());
        $this->assertSame([], $supplier->getSearchResults('Nile'));
        $this->assertSame([], $variants->getOptions());
        $this->assertSame([], $variants->getSearchResults($variant->sku));
    }
}
