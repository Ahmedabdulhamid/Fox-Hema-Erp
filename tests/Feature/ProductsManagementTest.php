<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\PermissionGroups;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Products\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Products\Filament\Resources\Categories\RelationManagers\ProductsRelationManager;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductAssignmentManager;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Products\Services\ProductReferenceChecker;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\ProductVariantManager;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\Taxes\Models\Tax;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ProductsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'products')->update(['is_active' => true]);
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

    private function scoped(array $permissions): array
    {
        $location = Location::create(['name' => 'Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'catalog-manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return [$user, $location, $role];
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);

            return;
        }
        $this->fail('The forbidden mutation was accepted.');
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function dependencyMigrations(array $modules): void
    {
        $paths = [];
        foreach ($modules as $module) {
            $paths = [...$paths, ...glob(app_path('Modules/'.$module.'/Database/Migrations/*.php'))];
        }
        sort($paths);
        // Sort by migration filename across the swapped Taxes/Discounts directories.
        usort($paths, fn (string $a, string $b): int => basename($a) <=> basename($b));
        foreach ($paths as $path) {
            if (DB::table('migrations')->where('migration', basename($path, '.php'))->exists()) {
                continue;
            }
            (require $path)->up();
        }
    }

    public function test_permissions_are_registered_grouped_and_synchronized_without_resetting_settings(): void
    {
        $actor = $this->admin();
        $historical = Permission::create(['name' => 'legacy.catalog_export', 'guard_name' => 'web']);
        app(ModuleRegistry::class)->register();
        app(ModuleRegistry::class)->register();
        $this->assertTrue(Module::where('code', 'products')->firstOrFail()->is_active);
        $this->assertEqualsCanonicalizing(ProductPermissions::all(), Module::where('code', 'products')->firstOrFail()->permissions->pluck('name')->all());
        $super = Role::where('name', 'super_admin')->whereNull('location_id')->firstOrFail();
        $this->assertTrue($super->permissions()->whereKey($historical)->exists());
        $this->assertSame(count(ProductPermissions::all()), $super->permissions()->whereIn('name', ProductPermissions::all())->count());
        $this->assertArrayHasKey('Products', app(PermissionGroups::class)->options($actor));
    }

    public function test_product_form_keeps_identifiers_and_prices_in_the_variant_form(): void
    {
        $this->admin();
        $component = Livewire::test(CreateProduct::class)->assertDontSee('Inventory costing');
        foreach (['default_sku', 'sku', 'barcode', 'sale_price', 'cost_price', 'simple_variant.sku', 'simple_variant.barcode', 'simple_variant.sale_price', 'simple_variant.cost_price'] as $field) {
            $component->assertFormFieldDoesNotExist($field);
        }
        $component->fillForm([
            'name' => 'Wizard product',
            'description' => '<p>A <strong>formatted</strong> description.</p>',
        ])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Wizard product')->firstOrFail();
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertFalse($product->is_active);
        $this->assertSame(0, $product->variants()->count());
        $this->assertStringContainsString('<strong>formatted</strong>', $product->description);

        $variants = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $variants->callAction(TestAction::make('create')->table(), [
            'sale_price' => '125.5000', 'cost_price' => '80.2500', 'is_active' => true,
        ])->assertHasNoFormErrors();
        $variant = $product->variants()->firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $variant->sku);
        $this->assertMatchesRegularExpression('/^20[0-9]{11}$/', $variant->barcode);
        $originalSku = $variant->sku;
        $originalBarcode = $variant->barcode;
        $this->assertSame('125.5000', $variant->sale_price);
        $this->assertSame('80.2500', $variant->cost_price);

        $edit = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        foreach (['default_sku', 'sku', 'barcode', 'sale_price', 'cost_price', 'simple_variant.sku', 'simple_variant.barcode', 'simple_variant.sale_price', 'simple_variant.cost_price'] as $field) {
            $edit->assertFormFieldDoesNotExist($field);
        }
        $this->assertNull($edit->get('data.simple_variant'));
        $edit->fillForm(['name' => 'Updated product'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated product', $product->fresh()->name);
        $this->assertSame('125.5000', $variant->fresh()->sale_price);
        $this->assertSame('80.2500', $variant->fresh()->cost_price);

        $variants->callAction(TestAction::make('edit')->table($variant), [
            'sale_price' => '130.0000', 'cost_price' => '85.0000', 'is_active' => true,
        ])->assertHasNoFormErrors();
        $this->assertSame($originalSku, $variant->fresh()->sku);
        $this->assertSame($originalBarcode, $variant->fresh()->barcode);
        $this->assertSame('130.0000', $variant->fresh()->sale_price);
        $this->assertSame('85.0000', $variant->fresh()->cost_price);
    }

    public function test_inline_price_mutations_require_variant_and_price_permissions_and_roll_back_product_changes(): void
    {
        [$actor] = $this->scoped(['products.view', 'products.update', 'products.variants.update']);
        $product = Product::factory()->create(['name' => 'Original']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'size_id' => null, 'color_id' => null, 'cost_price' => '987.4321']);
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $this->assertNull($component->get('data.simple_variant.cost_price'));
        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])->assertDontSee('987.4321');
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Forged', 'simple_variant' => ['sale_price' => '999.0000']], $product), HttpException::class);
        $this->assertSame('Original', $product->fresh()->name);
        $this->assertSame('987.4321', $variant->fresh()->cost_price);
    }

    public function test_category_form_saves_existing_costing_relationship_and_notes(): void
    {
        $this->admin();
        $this->dependencyMigrations(['Inventory', 'InventoryCosting']);
        Module::where('code', 'inventory_costing')->update(['is_active' => true]);
        Livewire::test(CreateCategory::class)->fillForm([
            'name' => 'Costed category', 'inventory_costing' => ['costing_method' => 'fifo', 'note' => 'FIFO category'],
        ])->call('create')->assertHasNoFormErrors();
        $category = Category::where('name', 'Costed category')->firstOrFail();
        $this->assertSame('fifo', $category->inventoryValuationSetting->costing_method);
        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->assertFormSet(['inventory_costing.costing_method' => 'fifo'])->fillForm(['inventory_costing.note' => 'Updated note'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Updated note', $category->inventoryValuationSetting()->firstOrFail()->note);
        $this->assertSame(1, $category->inventoryValuationSetting()->count());
    }

    public function test_category_costing_is_locked_after_stock_references_but_notes_remain_editable(): void
    {
        $actor = $this->admin();
        $this->dependencyMigrations(['Inventory', 'InventoryCosting']);
        Module::where('code', 'inventory_costing')->update(['is_active' => true]);
        $category = Category::factory()->create();
        $manager = app(CategoryCostingManager::class);
        $manager->save($actor, $category, ['costing_method' => 'avco']);
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => $category->id])->id]);
        $location = Location::create(['name' => 'Costing warehouse', 'type' => 'warehouse', 'is_active' => true]);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $location->id, 'quantity_on_hand' => 0, 'quantity_available' => 0]);
        $this->reject(fn () => $manager->save($actor, $category, ['costing_method' => 'fifo']));
        $this->assertSame('avco', $category->inventoryValuationSetting()->firstOrFail()->costing_method);
        $manager->save($actor, $category, ['costing_method' => 'avco', 'note' => 'Reviewed']);
        $this->assertSame('Reviewed', $category->inventoryValuationSetting()->firstOrFail()->note);
    }

    public function test_costing_mutations_enforce_permissions_module_activation_and_location_membership(): void
    {
        $this->dependencyMigrations(['Inventory', 'InventoryCosting']);
        Module::where('code', 'inventory_costing')->update(['is_active' => true]);
        [$actor, $location, $role] = $this->scoped(['products.categories.view', 'products.categories.update', 'products.categories.view_costing']);
        $category = Category::factory()->create();
        $manager = app(CategoryCostingManager::class);
        $this->reject(fn () => $manager->save($actor, $category, ['costing_method' => 'fifo']), HttpException::class);
        $role->givePermissionTo('products.categories.update_costing');
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $manager->save($actor, $category, ['costing_method' => 'fifo']);
        Module::where('code', 'inventory_costing')->update(['is_active' => false]);
        $this->reject(fn () => $manager->save($actor, $category, ['costing_method' => 'avco']), HttpException::class);
        Module::where('code', 'inventory_costing')->update(['is_active' => true]);
        setPermissionsTeamId(Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true])->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->reject(fn () => $manager->save($actor, $category, ['costing_method' => 'avco']), HttpException::class);
        $this->assertSame('fifo', $category->inventoryValuationSetting()->firstOrFail()->costing_method);
    }

    public function test_product_tabs_use_arabic_labels_and_keep_costing_configuration_on_categories(): void
    {
        $this->admin();
        app()->setLocale('ar');
        Livewire::test(CreateProduct::class)->assertSee(__('Product Information'))->assertDontSee('البيع والأسعار')->assertSee('الوصف')->assertDontSee('Inventory costing')->assertDontSee('تقييم وتكلفة المخزون');
    }

    public function test_category_products_relation_only_lists_products_assigned_to_that_category(): void
    {
        $this->admin();
        $category = Category::factory()->create();
        $assigned = Product::factory()->create(['category_id' => $category->id]);
        $other = Product::factory()->create(['category_id' => Category::factory()->create()->id]);
        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $category,
            'pageClass' => EditCategory::class,
        ])->assertCanSeeTableRecords([$assigned])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_rich_description_is_sanitized_without_losing_supported_formatting(): void
    {
        $product = app(ProductCatalogManager::class)->save($this->admin(), Product::class, ['name' => 'Safe content', 'description' => '<p><strong>Safe</strong></p><script>alert(1)</script><a href="javascript:alert(2)">Link</a>']);
        $this->assertStringContainsString('<strong>Safe</strong>', $product->description);
        $this->assertStringNotContainsString('<script', $product->description);
        $this->assertStringNotContainsString('javascript:', $product->description);
    }

    public function test_new_products_are_draft_and_activation_requires_a_variant(): void
    {
        $actor = $this->admin();
        $manager = app(ProductCatalogManager::class);
        $product = $manager->save($actor, Product::class, ['name' => ' Shirt ']);
        $this->assertSame('Shirt', $product->name);
        $this->assertSame(ProductStatus::Draft, $product->status);
        $this->assertFalse($product->is_active);
        $this->reject(fn () => $manager->save($actor, Product::class, ['name' => 'Wrong', 'status' => 'active', 'is_active' => true]));
        $this->reject(fn () => $manager->save($actor, Product::class, ['name' => 'Shirt', 'status' => 'active', 'is_active' => true], $product));
        app(ProductVariantManager::class)->save($actor, $product, ['sku' => 'SIMPLE']);
        $product = $manager->save($actor, Product::class, ['name' => 'Shirt', 'status' => 'active', 'is_active' => true], $product);
        $this->assertTrue($product->is_active);
        $this->reject(fn () => app(ProductVariantManager::class)->save($actor, $product, ['sku' => 'SIMPLE', 'is_active' => false], $product->variants->first()));
        $manager->save($actor, Product::class, ['name' => 'Shirt', 'status' => 'archived', 'is_active' => true], $product);
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_product_form_saves_selected_status_on_creation_and_editing(): void
    {
        $this->admin();
        foreach (['draft' => ProductStatus::Draft, 'active' => ProductStatus::Active, 'archived' => ProductStatus::Archived] as $status => $expected) {
            Livewire::test(CreateProduct::class)->assertFormFieldIsEnabled('status')
                ->fillForm(['name' => 'Status '.$status, 'status' => $status])
                ->call('create')->assertHasNoFormErrors();
            $product = Product::where('name', 'Status '.$status)->firstOrFail();
            $this->assertSame($expected, $product->status);
            $this->assertFalse($product->is_active);
        }
        $product = Product::where('name', 'Status draft')->firstOrFail();
        ProductVariant::factory()->create(['product_id' => $product->id]);
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertFormFieldIsEnabled('status')
            ->fillForm(['status' => 'active', 'is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame(ProductStatus::Active, $product->fresh()->status);
        $this->assertTrue($product->fresh()->is_active);
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['status' => 'archived'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(ProductStatus::Archived, $product->fresh()->status);
        $this->assertFalse($product->fresh()->is_active);
    }

    public static function dimensions(): array
    {
        return ['both null' => [false, false], 'size only' => [true, false], 'color only' => [false, true], 'both' => [true, true]];
    }

    #[DataProvider('dimensions')]
    public function test_duplicate_variant_combinations_are_rejected_including_nulls(bool $size, bool $color): void
    {
        $actor = $this->admin();
        $product = Product::factory()->create();
        $data = ['size_id' => $size ? Size::factory()->create()->id : null, 'color_id' => $color ? Color::factory()->create()->id : null];
        $manager = app(ProductVariantManager::class);
        $manager->save($actor, $product, ['sku' => 'FIRST', ...$data]);
        $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'SECOND', ...$data]));
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_variable_variants_are_distinct_and_cannot_mix_with_a_base_variant(): void
    {
        $actor = $this->admin();
        $product = Product::factory()->create();
        $manager = app(ProductVariantManager::class);
        $size = Size::factory()->create();
        $other = Size::factory()->create();
        $manager->save($actor, $product, ['sku' => 'A', 'size_id' => $size->id]);
        $manager->save($actor, $product, ['sku' => 'B', 'size_id' => $other->id]);
        $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'BASE']));
        $this->assertSame(2, $product->variants()->count());
    }

    public function test_identifiers_and_prices_are_validated_without_destroying_barcode_zeroes(): void
    {
        $actor = $this->admin();
        $product = Product::factory()->create();
        $manager = app(ProductVariantManager::class);
        $variant = $manager->save($actor, $product, ['sku' => '  SKU-1  ', 'barcode' => '001234', 'sale_price' => '12.3456', 'cost_price' => '5.1234']);
        $this->assertSame('SKU-1', $variant->sku);
        $this->assertSame('001234', $variant->barcode);
        $this->assertSame('12.3456', $variant->sale_price);
        $manager->save($actor, $product, ['sku' => 'SKU-1', 'barcode' => ''], $variant);
        $this->assertNull($variant->fresh()->barcode);
        foreach (['-1', '1.12345', '100000000000000'] as $price) {
            $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'SKU-1', 'sale_price' => $price], $variant));
        }
        $other = Product::factory()->create();
        $this->reject(fn () => $manager->save($actor, $other, ['sku' => 'SKU-1']));
        $manager->save($actor, $product, ['sku' => 'SKU-1', 'barcode' => '001234'], $variant);
        $this->reject(fn () => $manager->save($actor, $other, ['sku' => 'OTHER', 'barcode' => '001234']));
    }

    public function test_variant_model_generates_unique_identifiers_on_creation_and_preserves_them_on_update(): void
    {
        $first = ProductVariant::factory()->create(['sku' => null, 'barcode' => null]);
        $second = ProductVariant::factory()->create(['sku' => null, 'barcode' => null]);
        $this->assertNotSame($first->sku, $second->sku);
        $this->assertNotSame($first->barcode, $second->barcode);
        foreach ([$first, $second] as $variant) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $variant->sku);
            $this->assertMatchesRegularExpression('/^20[0-9]{11}$/', $variant->barcode);
            $sum = 0;
            for ($index = 0; $index < 13; $index++) {
                $sum += (int) $variant->barcode[$index] * ($index % 2 === 0 ? 1 : 3);
            }
            $this->assertSame(0, $sum % 10);
        }
        $sku = $first->sku;
        $barcode = $first->barcode;
        $first->update(['sale_price' => '19.5000']);
        $this->assertSame($sku, $first->fresh()->sku);
        $this->assertSame($barcode, $first->fresh()->barcode);
    }

    public function test_prices_and_reference_cost_require_separate_permissions_in_services_and_policies(): void
    {
        [$actor] = $this->scoped(['products.view', 'products.variants.create', 'products.variants.update']);
        $product = Product::factory()->create();
        $manager = app(ProductVariantManager::class);
        $variant = $manager->save($actor, $product, ['sku' => 'ALLOWED']);
        $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'ALLOWED', 'sale_price' => '2'], $variant), HttpException::class);
        $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'ALLOWED', 'cost_price' => '2'], $variant), HttpException::class);
        $this->assertFalse($actor->can('viewReferenceCost', $variant));
        $this->assertFalse($actor->can('updateSalePrice', $variant));
        $actor->givePermissionTo(['products.update_prices', 'products.update_reference_cost']);
        $manager->save($actor, $product, ['sku' => 'ALLOWED', 'sale_price' => '2.5000'], $variant);
        $this->reject(fn () => $manager->save($actor, $product, ['sku' => 'ALLOWED', 'cost_price' => '2'], $variant), HttpException::class);
        $actor->givePermissionTo('products.view_reference_cost');
        $manager->save($actor, $product, ['sku' => 'ALLOWED', 'cost_price' => '1.2500'], $variant);
        $this->assertSame('1.2500', $variant->fresh()->cost_price);
    }

    public function test_products_only_user_can_enter_panel_and_permissions_do_not_cross_locations(): void
    {
        [$actor, $first] = $this->scoped(['products.view', 'products.create']);
        $this->assertTrue($actor->canAccessPanel(Filament::getPanel('admin')));
        $second = Location::create(['name' => 'Second', 'type' => 'branch', 'is_active' => true]);
        $actor->locations()->attach($second);
        app(LocationContext::class)->setFor($actor, $second->id);
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Wrong branch']), HttpException::class);
        $this->assertFalse($actor->can('create', Product::class));
        app(LocationContext::class)->setFor($actor, $first->id);
        $this->assertTrue($actor->can('create', Product::class));
        $first->update(['is_active' => false]);
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Inactive branch']), HttpException::class);
    }

    public function test_inactive_module_denies_super_admin_services_policies_and_urls(): void
    {
        $actor = $this->admin();
        $product = Product::factory()->create();
        Module::where('code', 'products')->update(['is_active' => false]);
        $this->assertFalse($actor->can('view', $product));
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Denied']), HttpException::class);
        $this->get(ProductResource::getUrl('edit', ['record' => $product]))->assertForbidden();
    }

    public function test_category_cycles_inactive_lookups_and_referenced_lookup_deletion_are_blocked(): void
    {
        $actor = $this->admin();
        $manager = app(ProductCatalogManager::class);
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $this->reject(fn () => $manager->save($actor, Category::class, ['name' => $parent->name, 'code' => $parent->code, 'parent_id' => $child->id], $parent));
        $this->reject(fn () => $manager->delete($actor, $parent));
        $brand = Brand::factory()->create(['is_active' => false]);
        $this->reject(fn () => $manager->save($actor, Product::class, ['name' => 'Wrong', 'brand_id' => $brand->id]));
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $manager->save($actor, Product::class, ['name' => 'Preserved', 'brand_id' => $brand->id], $product);
        $this->reject(fn () => $manager->delete($actor, $brand));
        $size = Size::factory()->create();
        ProductVariant::factory()->create(['size_id' => $size->id]);
        $this->reject(fn () => $manager->delete($actor, $size));
        $this->reject(fn () => $manager->save($actor, Color::class, ['name' => 'Bad', 'hex_code' => '#ZZZZZZ']));
    }

    public static function referenceTables(): array
    {
        return array_combine(ProductReferenceChecker::VARIANT_TABLES, array_map(fn (string $table): array => [$table], ProductReferenceChecker::VARIANT_TABLES));
    }

    #[DataProvider('referenceTables')]
    public function test_each_business_reference_blocks_identity_changes_and_deletion(string $table): void
    {
        $actor = $this->admin();
        $variant = ProductVariant::factory()->create(['barcode' => '001']);
        $models = [
            'inventory_cost_states' => InventoryCostState::class,
            'inventory_standard_costs' => InventoryStandardCost::class,
            'fifo_layers' => FifoLayer::class,
            'purchase_order_items' => PurchaseOrderItem::class,
            'vendor_bill_items' => VendorBillItem::class,
            'sales_order_items' => SalesOrderItem::class,
            'sales_invoice_items' => SalesInvoiceItem::class,
            'sales_return_items' => SalesReturnItem::class,
            'stock_transfer_items' => StockTransferItem::class,
            'inventory_size_allocation_items' => SizeAllocationItem::class,
        ];
        if ($table === 'stock_balances') {
            $location = Location::create(['name' => 'Reference branch', 'type' => 'branch', 'is_active' => true]);
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $location->id]);
        } elseif ($table === 'stock_movements') {
            $location = Location::create(['name' => 'Reference branch', 'type' => 'branch', 'is_active' => true]);
            StockMovement::create(['variant_id' => $variant->id, 'location_id' => $location->id, 'type' => 'purchase', 'quantity' => '1.0000']);
        } else {
            if ($table === 'inventory_size_allocation_items') {
                $variant->update(['size_id' => Size::factory()->create()->id]);
            }
            $models[$table]::factory()->create(['variant_id' => $variant->id]);
        }
        $manager = app(ProductVariantManager::class);
        $this->reject(fn () => $manager->save($actor, $variant->product, ['sku' => 'RENAMED'], $variant));
        $this->reject(fn () => $manager->save($actor, $variant->product, ['sku' => $variant->sku, 'barcode' => '002'], $variant));
        $this->reject(fn () => $manager->save($actor, $variant->product, ['sku' => $variant->sku, 'barcode' => '1'], $variant));
        $this->reject(fn () => $manager->save($actor, $variant->product, ['sku' => $variant->sku, 'size_id' => Size::factory()->create()->id], $variant));
        $this->reject(fn () => $manager->delete($actor, $variant->product, $variant));
        $manager->save($actor, $variant->product, ['sku' => $variant->sku, 'sale_price' => '9.0000'], $variant);
        $this->assertSame('9.0000', $variant->fresh()->sale_price);
        $this->assertDatabaseHas($table, ['variant_id' => $variant->id]);
    }

    public function test_unused_variants_and_drafts_can_be_deleted_but_active_products_cannot(): void
    {
        $actor = $this->admin();
        $variant = ProductVariant::factory()->create();
        $product = $variant->product;
        $this->reject(fn () => app(ProductCatalogManager::class)->delete($actor, $product));
        app(ProductVariantManager::class)->delete($actor, $product, $variant);
        app(ProductCatalogManager::class)->delete($actor, $product);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $other = Product::factory()->create(['status' => 'active', 'is_active' => false]);
        $this->reject(fn () => app(ProductCatalogManager::class)->delete($actor, $other));
    }

    public function test_variant_owner_cannot_be_forged(): void
    {
        $actor = $this->admin();
        $variant = ProductVariant::factory()->create();
        $other = Product::factory()->create();
        $this->reject(fn () => app(ProductVariantManager::class)->save($actor, $variant->product, ['sku' => 'HACK', 'product_id' => $other->id], $variant), HttpException::class);
        $this->reject(fn () => app(ProductVariantManager::class)->delete($actor, $other, $variant), ModelNotFoundException::class);
    }

    public function test_image_ownership_primary_scopes_upload_validation_and_cleanup(): void
    {
        Storage::fake('public');
        $actor = $this->admin();
        $variant = ProductVariant::factory()->create();
        $product = $variant->product;
        $manager = app(ProductImageManager::class);
        $first = $manager->save($actor, $product, ['upload' => $this->upload(), 'is_primary' => true]);
        $second = $manager->save($actor, $product, ['upload' => $this->upload(), 'is_primary' => true]);
        $specific = $manager->save($actor, $product, ['upload' => $this->upload(), 'product_variant_id' => $variant->id, 'is_primary' => true]);
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->is_primary);
        $this->assertTrue($specific->is_primary);
        Storage::disk('public')->assertExists($second->path);
        $foreign = ProductVariant::factory()->create();
        $legacy = ProductImage::factory()->create(['product_id' => $product->id, 'product_variant_id' => $foreign->id]);
        $this->reject(fn () => $manager->save($actor, $product, ['is_primary' => true], $legacy));
        $this->reject(fn () => $manager->save($actor, $product, ['upload' => $this->upload(), 'product_variant_id' => $foreign->id]));
        $this->reject(fn () => $manager->save($actor, $product, ['upload' => UploadedFile::fake()->create('bad.php', 2, 'text/plain')]));
        $this->reject(fn () => $manager->save($actor, $product, ['path' => 'other/private.png'], $first), HttpException::class);
        $old = $second->path;
        $second = $manager->save($actor, $product, ['upload' => $this->upload()], $second);
        $manager->delete($actor, $product, $second);
        // RefreshDatabase wraps tests in a transaction: process the durable tasks explicitly.
        $this->artisan('products:cleanup-images')->assertSuccessful();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertMissing($second->path);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_cleanup_preserves_shared_files_and_records_retryable_failures(): void
    {
        Storage::fake('public');
        $actor = $this->admin();
        $product = Product::factory()->create();
        Storage::disk('public')->put('products/shared.png', 'shared');
        $first = ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'products/shared.png']);
        ProductImage::factory()->create(['product_id' => $product->id, 'path' => $first->path]);
        app(ProductImageManager::class)->delete($actor, $product, $first);
        $this->artisan('products:cleanup-images')->assertSuccessful();
        Storage::disk('public')->assertExists('products/shared.png');
        $legacy = ProductImage::factory()->create(['product_id' => $product->id, 'path' => 'legacy/image.png']);
        app(ProductImageManager::class)->delete($actor, $product, $legacy);
        $this->artisan('products:cleanup-images')->assertFailed();
        $this->assertDatabaseHas('product_image_cleanup_tasks', ['path' => 'legacy/image.png']);
        $this->assertGreaterThanOrEqual(1, DB::table('product_image_cleanup_tasks')->where('path', 'legacy/image.png')->value('attempts'));
        $this->artisan('products:cleanup-images', ['--limit' => '0'])->assertFailed();
    }

    public function test_assignments_require_available_modules_permissions_and_valid_definitions(): void
    {
        $actor = $this->admin();
        $product = Product::factory()->create();
        $manager = app(ProductAssignmentManager::class);
        $this->assertFalse($manager->available('taxes'));
        $this->reject(fn () => $manager->sync($actor, $product, 'taxes', []), HttpException::class);
        $this->dependencyMigrations(['Taxes', 'Discounts']);
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => true]);
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $manager->sync($actor, $product, 'taxes', [$tax->id]);
        $manager->sync($actor, $product, 'discounts', [$discount->id]);
        $this->assertTrue($product->taxes()->whereKey($tax)->exists());
        $tax->update(['is_active' => false]);
        $manager->sync($actor, $product, 'taxes', [$tax->id]);
        $bad = Discount::factory()->create(['scope' => 'order']);
        $this->reject(fn () => $manager->sync($actor, $product, 'discounts', [$bad->id]));
        [$scoped] = $this->scoped(['products.view']);
        $this->reject(fn () => $manager->sync($scoped, $product, 'taxes', []), HttpException::class);
        Module::where('code', 'taxes')->update(['is_active' => false]);
        $this->assertFalse($manager->available('taxes'));
    }

    public function test_filament_pages_and_relation_managers_use_the_services(): void
    {
        $this->admin();
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'From Filament'])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'From Filament')->firstOrFail();
        $this->assertSame(ProductStatus::Draft, $product->status);
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertSuccessful();
        $variants = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $variants->mountAction(TestAction::make('create')->table())
            ->assertFormFieldDoesNotExist('sku')
            ->assertFormFieldDoesNotExist('barcode')
            ->unmountAction()
            ->callAction(TestAction::make('create')->table(), ['sale_price' => '5.0000', 'is_active' => true])->assertHasNoFormErrors();
        $variant = $product->variants()->firstOrFail();
        $this->assertNotEmpty($variant->sku);
        $this->assertNotEmpty($variant->barcode);
        $variants->mountAction(TestAction::make('edit')->table($variant))
            ->assertFormFieldDoesNotExist('sku')
            ->assertFormFieldDoesNotExist('barcode');
        foreach (['brands', 'categories', 'sizes', 'colors', 'products'] as $resource) {
            $this->get(route('filament.admin.resources.'.$resource.'.index'))->assertOk();
        }
    }

    public function test_reference_cost_is_absent_from_unauthorized_livewire_form_state(): void
    {
        [$actor] = $this->scoped(['products.view', 'products.variants.update']);
        $variant = ProductVariant::factory()->create(['cost_price' => '987.6543']);
        $component = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])->mountAction(TestAction::make('edit')->table($variant));
        $this->assertStringNotContainsString('987.6543', $component->html());
        $this->assertArrayNotHasKey('cost_price', $component->get('mountedActions')[0]['data']);
        $this->assertFalse($actor->can('viewReferenceCost', $variant));
    }

    public function test_open_livewire_page_denies_mutation_after_module_deactivation(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $component->fillForm(['name' => 'Hacked']);
        Module::where('code', 'products')->update(['is_active' => false]);
        $component->call('save')->assertForbidden();
        $this->assertNotSame('Hacked', $product->fresh()->name);
    }

    public function test_image_mutations_require_permissions_and_reject_foreign_owners(): void
    {
        Storage::fake('public');
        [$actor] = $this->scoped(['products.view']);
        $product = Product::factory()->create();
        $image = ProductImage::factory()->create(['product_id' => $product->id]);
        $manager = app(ProductImageManager::class);
        $this->reject(fn () => $manager->save($actor, $product, ['upload' => $this->upload()]), HttpException::class);
        $this->reject(fn () => $manager->save($actor, $product, ['is_primary' => true], $image), HttpException::class);
        $this->reject(fn () => $manager->delete($actor, $product, $image), HttpException::class);
        $admin = $this->admin();
        $other = Product::factory()->create();
        $this->reject(fn () => $manager->delete($admin, $other, $image), ModelNotFoundException::class);
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'product_id' => $product->id]);
    }

    public function test_cleanup_retries_storage_failures_and_abandoned_uploads(): void
    {
        Storage::fake('public');
        $filesystem = Storage::getFacadeRoot();
        $path = 'products/pending.png';
        Storage::disk('public')->put($path, 'image');
        $id = DB::table('product_image_cleanup_tasks')->insertGetId(['disk' => 'public', 'path' => $path, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->with($path)->andReturn(true);
        $disk->shouldReceive('delete')->with($path)->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        $this->assertFalse(app(ProductImageManager::class)->cleanup($id));
        $this->assertDatabaseHas('product_image_cleanup_tasks', ['id' => $id, 'attempts' => 1]);
        Storage::swap($filesystem);
        $this->assertTrue(app(ProductImageManager::class)->cleanup($id));
        Storage::disk('public')->assertMissing($path);
        $future = DB::table('product_image_cleanup_tasks')->insertGetId(['disk' => 'public', 'path' => 'products/in-progress.png', 'ready_at' => now()->addHour(), 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('public')->put('products/in-progress.png', 'in progress');
        $this->artisan('products:cleanup-images')->assertSuccessful();
        Storage::disk('public')->assertExists('products/in-progress.png');
        $this->travel(2)->hours();
        $this->artisan('products:cleanup-images')->assertSuccessful();
        Storage::disk('public')->assertMissing('products/in-progress.png');
        $this->assertDatabaseMissing('product_image_cleanup_tasks', ['id' => $future]);
    }

    public function test_real_inventory_and_costing_relationships_preserve_accounting_cost(): void
    {
        $this->dependencyMigrations(['Inventory', 'InventoryCosting']);
        $actor = $this->admin();
        $category = Category::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => $category->id])->id]);
        $location = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $balance = StockBalance::create(['variant_id' => $variant->id, 'location_id' => $location->id, 'quantity_on_hand' => '3.0000', 'quantity_available' => '3.0000']);
        $state = InventoryCostState::factory()->create(['variant_id' => $variant->id, 'avg_cost' => '7.123456']);
        $standard = InventoryStandardCost::factory()->create(['variant_id' => $variant->id, 'standard_cost' => '8.123456']);
        $this->assertTrue($variant->stockBalances->first()->is($balance));
        app(ProductVariantManager::class)->save($actor, $variant->product, ['sku' => $variant->sku, 'cost_price' => '2.0000'], $variant);
        $this->assertSame('7.123456', $state->fresh()->avg_cost);
        $this->assertSame('8.123456', $standard->fresh()->standard_cost);
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => $variant->product->name, 'category_id' => null], $variant->product));
        InventoryValuationSetting::factory()->create(['category_id' => $category->id]);
        $this->reject(fn () => app(ProductCatalogManager::class)->delete($actor, $category));
    }

    public function test_filament_image_upload_primary_action_and_replacement(): void
    {
        Storage::fake('public');
        $this->admin();
        $product = Product::factory()->create();
        $component = Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
        $component->callAction(TestAction::make('create')->table(), ['upload' => $this->upload(), 'alt_text' => 'Front', 'sort_order' => 1, 'is_primary' => false])->assertHasNoFormErrors();
        $image = $product->images()->firstOrFail();
        Storage::disk('public')->assertExists($image->path);
        $component->callAction(TestAction::make('make_primary')->table($image))->assertHasNoFormErrors();
        $this->assertTrue($image->fresh()->is_primary);
        $old = $image->path;
        $component->callAction(TestAction::make('edit')->table($image), ['upload' => $this->upload(), 'alt_text' => 'Updated', 'sort_order' => 2, 'is_primary' => true])->assertHasNoFormErrors();
        $this->assertNotSame($old, $image->fresh()->path);
        $this->artisan('products:cleanup-images')->assertSuccessful();
        Storage::disk('public')->assertMissing($old);
    }

    public function test_filament_assignment_actions_and_dependency_deactivation(): void
    {
        $this->admin();
        $this->dependencyMigrations(['Taxes', 'Discounts']);
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => true]);
        $product = Product::factory()->create();
        $tax = Tax::factory()->create();
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $component->callAction('assign_taxes', ['ids' => [$tax->id]])->assertHasNoFormErrors();
        $this->assertSame([$tax->id], $product->taxes()->pluck('taxes.id')->all());
        Module::where('code', 'taxes')->update(['is_active' => false]);
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertActionHidden('assign_taxes');
    }

    public function test_product_selects_create_complete_tax_and_discount_definitions_and_save_assignments(): void
    {
        $this->admin();
        $this->dependencyMigrations(['Taxes', 'Discounts']);
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => true]);
        $existingTax = Tax::factory()->create();
        $existingDiscount = Discount::factory()->create();
        $page = Livewire::test(CreateProduct::class);
        $page->callAction(TestAction::make('createOption')->schemaComponent('tax_ids'), [
            'name' => 'VAT definition', 'code' => 'VAT-UI', 'type' => 'percentage', 'rate' => '14.0000',
            'price_inclusion' => 'included', 'usage' => 'both', 'is_active' => true,
            'effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addYear()->toDateString(),
        ])->assertHasNoFormErrors();
        $tax = Tax::where('code', 'VAT-UI')->firstOrFail();
        $this->assertSame('14.0000', $tax->rate);
        $this->assertSame('included', $tax->price_inclusion);
        $this->assertSame('both', $tax->usage);
        $this->assertSame(now()->subDay()->toDateString(), $tax->effective_from->toDateString());
        $this->assertSame(now()->addYear()->toDateString(), $tax->effective_to->toDateString());
        $page->assertFormSet(['tax_ids' => [$tax->id]]);

        $page->callAction(TestAction::make('createOption')->schemaComponent('discount_ids'), [
            'name' => 'Discount definition', 'code' => 'DISCOUNT-UI', 'type' => 'fixed', 'value' => '25.5000',
            'scope' => 'product', 'is_active' => true, 'is_stackable' => true,
            'starts_at' => now()->subDay()->startOfDay()->toDateTimeString(), 'ends_at' => now()->addYear()->startOfDay()->toDateTimeString(),
        ])->assertHasNoFormErrors();
        $discount = Discount::where('code', 'DISCOUNT-UI')->firstOrFail();
        $this->assertSame('25.5000', $discount->value);
        $this->assertSame('fixed', $discount->type);
        $this->assertSame('product', $discount->scope);
        $this->assertTrue($discount->is_stackable);
        $this->assertSame(now()->subDay()->startOfDay()->toDateTimeString(), $discount->starts_at->toDateTimeString());
        $this->assertSame(now()->addYear()->startOfDay()->toDateTimeString(), $discount->ends_at->toDateTimeString());
        $page->assertFormSet(['discount_ids' => [$discount->id]]);

        $page->fillForm(['name' => 'Assigned product', 'tax_ids' => [$tax->id, $existingTax->id], 'discount_ids' => [$discount->id, $existingDiscount->id]])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Assigned product')->firstOrFail();
        $this->assertEqualsCanonicalizing([$tax->id, $existingTax->id], $product->taxes()->pluck('taxes.id')->all());
        $this->assertEqualsCanonicalizing([$discount->id, $existingDiscount->id], $product->discounts()->pluck('discounts.id')->all());
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertFormSet([
            'tax_ids' => [$existingTax->id, $tax->id], 'discount_ids' => [$existingDiscount->id, $discount->id],
        ])->fillForm(['tax_ids' => [], 'discount_ids' => [$discount->id]])->call('save')->assertHasNoFormErrors();
        $this->assertSame(0, $product->taxes()->count());
        $this->assertSame([$discount->id], $product->discounts()->pluck('discounts.id')->all());
    }

    public function test_inline_definition_creation_validates_codes_dates_and_values(): void
    {
        $this->admin();
        $this->dependencyMigrations(['Taxes', 'Discounts']);
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => true]);
        Tax::factory()->create(['code' => 'TAKEN']);
        Livewire::test(CreateProduct::class)->callAction(TestAction::make('createOption')->schemaComponent('tax_ids'), [
            'name' => 'Invalid tax', 'code' => 'TAKEN', 'rate' => '-1',
            'effective_from' => '2026-10-10', 'effective_to' => '2026-10-09',
        ])->assertHasFormErrors(['code' => 'unique', 'rate' => 'min', 'effective_to' => 'after_or_equal']);
        Livewire::test(CreateProduct::class)->callAction(TestAction::make('createOption')->schemaComponent('discount_ids'), [
            'name' => 'Invalid discount', 'value' => '-1', 'starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2026-10-09 00:00:00',
        ])->assertHasFormErrors(['value' => 'min', 'ends_at' => 'after_or_equal']);
        $this->assertDatabaseMissing('taxes', ['name' => 'Invalid tax']);
        $this->assertDatabaseMissing('discounts', ['name' => 'Invalid discount']);

        Livewire::test(CreateProduct::class)->callAction(TestAction::make('createOption')->schemaComponent('discount_ids'), [
            'name' => 'Discount with end only', 'value' => '5.0000', 'ends_at' => now()->addDay()->toDateTimeString(),
        ])->assertHasNoFormErrors();
        $discount = Discount::where('name', 'Discount with end only')->firstOrFail();
        $this->assertNull($discount->code);
        $this->assertNull($discount->starts_at);
    }

    public function test_product_assignment_selects_enforce_permissions_and_module_availability(): void
    {
        $this->dependencyMigrations(['Taxes', 'Discounts']);
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => true]);
        [$actor] = $this->scoped(['products.view', 'products.create', 'products.update']);
        Livewire::test(CreateProduct::class)->assertFormFieldIsHidden('tax_ids')->assertFormFieldIsHidden('discount_ids');
        $this->reject(fn () => app(ProductAssignmentManager::class)->createDefinition($actor, 'taxes', []), HttpException::class);
        $this->reject(fn () => app(ProductAssignmentManager::class)->createDefinition($actor, 'discounts', []), HttpException::class);
        $product = Product::factory()->create(['name' => 'Original assignment product']);
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Product::class, ['name' => 'Forged assignment', 'tax_ids' => []], $product), HttpException::class);
        $this->assertSame('Original assignment product', $product->fresh()->name);

        $admin = $this->admin();
        Module::whereIn('code', ['taxes', 'discounts'])->update(['is_active' => false]);
        Livewire::test(CreateProduct::class)->assertFormFieldIsHidden('tax_ids')->assertFormFieldIsHidden('discount_ids');
        $this->reject(fn () => app(ProductAssignmentManager::class)->createDefinition($admin, 'taxes', []), HttpException::class);
    }

    public function test_revoked_permissions_and_inactive_accounts_cannot_mutate_open_forms(): void
    {
        [$actor, $location, $role] = $this->scoped(['products.view', 'products.update']);
        $product = Product::factory()->create();
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->fillForm(['name' => 'Forbidden']);
        $role->revokePermissionTo('products.update');
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $component->call('save')->assertForbidden();
        $this->assertNotSame('Forbidden', $product->fresh()->name);
        $actor->is_active = false;
        $actor->save();
        $this->assertFalse(app(ProductsAccess::class)->allows($actor, 'products.view'));
    }

    public function test_all_lookup_resources_create_and_edit_through_validated_services(): void
    {
        $this->admin();
        foreach (['Brand' => 'Brands', 'Category' => 'Categories', 'Size' => 'Sizes', 'Color' => 'Colors'] as $model => $plural) {
            $namespace = 'App\\Modules\\Products\\Filament\\Resources\\'.$plural.'\\Pages\\';
            $data = ['name' => $model, 'is_active' => true];
            if (in_array($model, ['Size', 'Color'], true)) {
                $data['sort_order'] = 1;
            }
            if ($model === 'Color') {
                $data['hex_code'] = '#123456';
            }
            Livewire::test($namespace.'Create'.$model)->fillForm($data)->call('create')->assertHasNoFormErrors();
            $class = 'App\\Modules\\Products\\Models\\'.$model;
            $record = $class::where('name', $model)->firstOrFail();
            $data['name'] .= ' updated';
            Livewire::test($namespace.'Edit'.$model, ['record' => $record->getRouteKey()])->fillForm($data)->call('save')->assertHasNoFormErrors();
            $this->assertSame($data['name'], $record->fresh()->name);
        }
    }

    public function test_business_validation_errors_appear_on_filament_fields(): void
    {
        $this->admin();
        $product = Product::factory()->create();
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->fillForm(['name' => 'Activate', 'status' => 'active', 'is_active' => true])->call('save')->assertHasFormErrors(['is_active']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'sku' => 'EXISTING']);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])->callAction(TestAction::make('create')->table(), ['sku' => 'DUPLICATE', 'sale_price' => '0.0000', 'is_active' => true])->assertHasFormErrors(['size_id']);
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_category_model_generates_unique_codes_on_creation_and_preserves_existing_codes(): void
    {
        $actor = $this->admin();
        Livewire::test(CreateCategory::class)
            ->assertFormFieldDoesNotExist('code')
            ->fillForm(['name' => 'Automatic category', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::where('name', 'Automatic category')->firstOrFail();
        $originalCode = $category->code;
        $this->assertMatchesRegularExpression('/^CAT-[0-9A-Z]{26}$/', $originalCode);
        $other = Category::create(['name' => 'Another category', 'is_active' => true]);
        $this->assertNotSame($originalCode, $other->code);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->assertFormFieldDoesNotExist('code')
            ->fillForm(['name' => 'Renamed category'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($originalCode, $category->fresh()->code);
        $this->reject(fn () => app(ProductCatalogManager::class)->save($actor, Category::class, ['name' => 'Forged', 'code' => 'REPLACEMENT'], $category));
        $this->assertSame($originalCode, $category->fresh()->code);

        $legacy = Category::create(['name' => 'Imported category', 'code' => 'LEGACY-CATEGORY']);
        $legacy->update(['name' => 'Updated imported category']);
        $this->assertSame('LEGACY-CATEGORY', $legacy->fresh()->code);

        $this->expectException(QueryException::class);
        Category::create(['name' => 'Duplicate category', 'code' => $originalCode]);
    }
}
