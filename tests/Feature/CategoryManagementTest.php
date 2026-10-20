<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Products\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Products\Filament\Resources\Categories\Pages\ViewCategory;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductReferenceChecker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Inventory', 'InventoryCosting'] as $module) {
            $this->app->make('migrator')->path(app_path('Modules/'.$module.'/Database/Migrations'));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'inventory_costing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        $user = $this->provisionTestSuperAdmin();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, null);

        return $user;
    }

    private function editor(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $location = Location::create(['name' => 'Category branch', 'type' => 'branch', 'is_active' => true]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'category-editor', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_parent_options_only_show_active_roots_and_exclude_the_edited_category(): void
    {
        $this->admin();
        $root = Category::factory()->create(['name' => 'Allowed root']);
        $inactive = Category::factory()->create(['is_active' => false]);
        $child = Category::factory()->create(['parent_id' => $root->id]);

        Livewire::test(CreateCategory::class)->assertFormFieldExists('parent_id', function (Select $field) use ($root, $inactive, $child): bool {
            $this->assertSame([$root->id => $root->name], $field->getOptions());
            $this->assertArrayNotHasKey($inactive->id, $field->getOptions());
            $this->assertArrayNotHasKey($child->id, $field->getOptions());

            return true;
        });
        Livewire::test(EditCategory::class, ['record' => $root->slug])->assertFormFieldExists('parent_id', fn (Select $field): bool => $field->getOptions() === []);
    }

    public function test_service_rejects_inactive_non_root_self_and_circular_parent_assignments(): void
    {
        $actor = $this->admin();
        $manager = app(ProductCatalogManager::class);
        $root = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $root->id]);
        $inactive = Category::factory()->create(['is_active' => false]);
        foreach ([$root->id, $child->id, $inactive->id] as $parentId) {
            try {
                $manager->save($actor, Category::class, ['name' => $root->name, 'parent_id' => $parentId], $root);
                $this->fail('Invalid parent assignment was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('parent_id', $exception->errors());
            }
        }
        $this->assertNull($root->fresh()->parent_id);
        $this->assertSame($root->id, $child->fresh()->parent_id);
        Livewire::test(EditCategory::class, ['record' => $root->slug])->fillForm(['parent_id' => $child->id])->call('save')->assertHasFormErrors(['parent_id']);
    }

    public function test_inactive_parent_label_and_relationship_are_preserved_on_unrelated_edits(): void
    {
        $this->admin();
        $parent = Category::factory()->create(['name' => 'Preserved inactive parent', 'is_active' => false]);
        $category = Category::factory()->create(['parent_id' => $parent->id]);
        $identity = [$category->id, $category->slug, $category->uuid, $category->code];
        Livewire::test(EditCategory::class, ['record' => $category->slug])
            ->assertFormSet(['parent_id' => $parent->id])
            ->assertFormFieldExists('parent_id', function (Select $field) use ($parent): bool {
                $this->assertSame($parent->name, $field->getOptionLabel());
                $this->assertArrayNotHasKey($parent->id, $field->getOptions());

                return true;
            })
            ->fillForm(['name' => 'Renamed child'])->call('save')->assertHasNoFormErrors();
        $category->refresh();
        $this->assertSame($parent->id, $category->parent_id);
        $this->assertSame($identity, [$category->id, $category->slug, $category->uuid, $category->code]);
    }

    public function test_create_edit_and_view_reuse_costing_settings_and_notes(): void
    {
        $this->admin();
        Livewire::test(CreateCategory::class)->fillForm([
            'name' => 'Configured category', 'inventory_costing' => ['costing_method' => 'standard', 'note' => 'Initial costing note'],
        ])->call('create')->assertHasNoFormErrors();
        $category = Category::where('name', 'Configured category')->firstOrFail();
        $setting = $category->inventoryValuationSetting;
        $this->assertSame('standard', $setting->costing_method);
        Livewire::test(EditCategory::class, ['record' => $category->slug])->fillForm([
            'inventory_costing' => ['costing_method' => 'fifo', 'note' => 'Reviewed FIFO policy'],
        ])->call('save')->assertHasNoFormErrors();
        $this->assertSame($setting->id, $category->inventoryValuationSetting()->firstOrFail()->id);
        $this->assertSame($setting->uuid, $category->inventoryValuationSetting()->firstOrFail()->uuid);
        $this->assertSame('fifo', $setting->fresh()->costing_method);
        Livewire::test(ViewCategory::class, ['record' => $category->slug])->assertSee(__('First in, first out (FIFO)'))->assertSee('Reviewed FIFO policy');
        $this->assertSame(1, $category->inventoryValuationSetting()->count());
    }

    public function test_unrelated_edits_do_not_modify_existing_costing_settings_or_timestamps(): void
    {
        $this->admin();
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'standard', 'note' => 'Keep this policy']);
        $before = $setting->fresh()->getAttributes();
        $this->travel(1)->hours();
        Livewire::test(EditCategory::class, ['record' => $setting->category->slug])->fillForm(['name' => 'Renamed configured category'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($before, $setting->fresh()->getAttributes());
    }

    public function test_missing_settings_are_not_implicitly_created_during_an_unrelated_edit_with_inventory(): void
    {
        $this->admin();
        $category = Category::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => $category->id])->id]);
        $location = Location::create(['name' => 'Stock branch', 'type' => 'branch']);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $location->id, 'quantity_on_hand' => 0, 'quantity_available' => 0]);
        Livewire::test(EditCategory::class, ['record' => $category->slug])->assertFormSet(['inventory_costing.costing_method' => null])
            ->fillForm(['name' => 'Name only'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Name only', $category->fresh()->name);
        $this->assertFalse($category->inventoryValuationSetting()->exists());
        Livewire::test(ViewCategory::class, ['record' => $category->slug])->assertSee(__('Not configured'));
    }

    public function test_parent_settings_are_not_silently_inherited_by_child_categories(): void
    {
        $this->admin();
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'fifo']);
        $child = Category::factory()->create(['parent_id' => $setting->category_id]);
        Livewire::test(EditCategory::class, ['record' => $child->slug])->assertFormSet(['inventory_costing.costing_method' => null]);
        Livewire::test(ViewCategory::class, ['record' => $child->slug])->assertSee(__('Not configured'));
        $this->assertFalse($child->inventoryValuationSetting()->exists());
    }

    public function test_category_update_permission_alone_cannot_change_costing_through_forged_requests(): void
    {
        $actor = $this->editor(['products.categories.view', 'products.categories.update']);
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'avco', 'note' => 'Protected policy']);
        $category = $setting->category;
        Livewire::test(EditCategory::class, ['record' => $category->slug])->assertFormFieldIsHidden('inventory_costing.costing_method')
            ->set('data.inventory_costing.costing_method', 'fifo')->call('save')->assertHasNoFormErrors();
        $this->assertSame('avco', $setting->fresh()->costing_method);
        try {
            app(ProductCatalogManager::class)->save($actor, Category::class, ['name' => 'Forged', 'inventory_costing' => ['costing_method' => 'fifo']], $category);
            $this->fail('Unauthorized costing mutation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($category->name, $category->fresh()->name);
        $this->assertSame('Protected policy', $setting->fresh()->note);
    }

    public function test_costing_view_permission_allows_reading_but_not_updating_settings(): void
    {
        $this->editor(['products.categories.view', 'products.categories.update', 'products.categories.view_costing']);
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'fifo', 'note' => 'Read only note']);
        Livewire::test(EditCategory::class, ['record' => $setting->category->slug])->fillForm(['name' => 'Category edit without costing changes'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditCategory::class, ['record' => $setting->category->slug])->assertFormFieldIsDisabled('inventory_costing.costing_method')
            ->set('data.inventory_costing.costing_method', 'standard')->call('save');
        $this->assertSame('fifo', $setting->fresh()->costing_method);
        Livewire::test(ViewCategory::class, ['record' => $setting->category->slug])->assertSee('Read only note');
    }

    public function test_disabled_costing_module_blocks_super_admin_mutations_and_open_forms(): void
    {
        $actor = $this->admin();
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'avco']);
        $page = Livewire::test(EditCategory::class, ['record' => $setting->category->slug]);
        Module::where('code', 'inventory_costing')->update(['is_active' => false]);
        $page->set('data.inventory_costing.costing_method', 'fifo')->call('save');
        $this->assertSame('avco', $setting->fresh()->costing_method);
        Livewire::test(CreateCategory::class)->assertFormFieldIsHidden('inventory_costing.costing_method');
        try {
            app(CategoryCostingManager::class)->save($actor, $setting->category, ['costing_method' => 'fifo']);
            $this->fail('Inactive module mutation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_costing_permissions_do_not_bypass_location_membership(): void
    {
        $actor = $this->editor(['products.categories.view', 'products.categories.update', 'products.categories.view_costing', 'products.categories.update_costing']);
        $category = Category::factory()->create();
        $actor->locations()->detach();
        $this->expectException(HttpException::class);
        app(CategoryCostingManager::class)->save($actor, $category, ['costing_method' => 'fifo']);
    }

    public static function costingHistory(): array
    {
        return [
            'valuation state' => [InventoryCostState::class],
            'standard cost' => [InventoryStandardCost::class],
            'standard cost revisions' => [InventoryStandardCostRevision::class],
            'FIFO layer' => [FifoLayer::class],
            'cost entry' => [InventoryCostEntry::class],
            'FIFO consumption' => [FifoLayerConsumption::class],
        ];
    }

    #[DataProvider('costingHistory')]
    public function test_costing_history_blocks_method_changes_without_revaluing_records(string $class): void
    {
        $actor = $this->admin();
        $history = $class::factory()->create();
        $variantId = match ($class) {
            InventoryStandardCostRevision::class => $history->standardCost->variant_id,
            InventoryCostEntry::class => $history->stockMovement->variant_id,
            FifoLayerConsumption::class => $history->costEntry->stockMovement->variant_id,
            default => $history->variant_id,
        };
        $category = Category::factory()->create();
        ProductVariant::findOrFail($variantId)->product->update(['category_id' => $category->id]);
        $setting = InventoryValuationSetting::factory()->create(['category_id' => $category->id, 'costing_method' => 'avco']);
        $before = $history->fresh()->getAttributes();
        try {
            app(CategoryCostingManager::class)->save($actor, $category, ['costing_method' => 'fifo']);
            $this->fail('Unsafe method change was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_costing.costing_method', $exception->errors());
        }
        $this->assertSame('avco', $setting->fresh()->costing_method);
        $this->assertSame($before, $history->fresh()->getAttributes());
        app(CategoryCostingManager::class)->save($actor, $category, ['note' => 'History reviewed']);
        $this->assertSame('History reviewed', $setting->fresh()->note);
    }

    public function test_invalid_method_errors_are_shown_on_the_nested_costing_field(): void
    {
        $actor = $this->admin();
        $category = Category::factory()->create();
        $this->assertNull(app(CategoryCostingManager::class)->save($actor, $category, ['costing_method' => '', 'note' => null]));
        try {
            app(CategoryCostingManager::class)->save($actor, $category, ['costing_method' => 'unsupported']);
            $this->fail('Unsupported method was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('inventory_costing.costing_method', $exception->errors());
        }
        $this->assertFalse($category->inventoryValuationSetting()->exists());
    }

    public function test_category_reference_checks_do_not_add_queries_per_product_or_variant(): void
    {
        $category = Category::factory()->create();
        ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => $category->id])->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse(app(ProductReferenceChecker::class)->categoryIsReferenced($category));
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 12) as $index) {
            ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => $category->id])->id]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse(app(ProductReferenceChecker::class)->categoryIsReferenced($category));
        $this->assertSame($before, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
