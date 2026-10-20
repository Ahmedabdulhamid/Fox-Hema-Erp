<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\CreateSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductVariantManager;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Products\Services\VariantBarcode;
use App\Modules\Sales\Services\SalesOrderService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class Phase7ProductsTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'inventory', 'sales'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::factory()->create(['sale_price' => '15.1234', 'cost_price' => '7.5000',
            'product_id' => Product::factory()->create(['status' => 'active', 'is_active' => true])->id]);
    }

    private function savePrice(Product $product, array $data): Product
    {
        return app(ProductCatalogManager::class)->save($this->actor, Product::class, ['name' => $product->name] + $data, $product);
    }

    public function test_switching_modes_preserves_individual_prices_costs_and_identifiers(): void
    {
        $variant = $this->variant();
        $before = $variant->only(['sku', 'barcode', 'sale_price', 'cost_price']);
        $resolver = app(SellingPriceResolver::class);
        $this->assertSame('15.1234', $resolver->resolve($variant));
        $this->savePrice($variant->product, ['has_fixed_selling_price' => true, 'sale_price' => '1500.4321']);
        $this->assertSame('1500.4321', $resolver->resolve($variant->fresh()));
        $this->assertSame($before, $variant->fresh()->only(array_keys($before)));
        $this->savePrice($variant->product->fresh(), ['has_fixed_selling_price' => false]);
        $this->assertSame('15.1234', $resolver->resolve($variant->fresh()));
        $this->assertSame($before, $variant->fresh()->only(array_keys($before)));
    }

    public static function invalidPrices(): array
    {
        return [[null], ['-1'], ['1.12345'], ['100000000000000'], ['abc']];
    }

    #[DataProvider('invalidPrices')]
    public function test_fixed_price_requires_a_non_negative_four_decimal_price(mixed $price): void
    {
        $variant = $this->variant();
        $this->expectException(ValidationException::class);
        $this->savePrice($variant->product, ['has_fixed_selling_price' => true, 'sale_price' => $price]);
    }

    public function test_fixed_mode_blocks_individual_price_mutation_and_initializes_new_variants(): void
    {
        $product = Product::factory()->create(['has_fixed_selling_price' => true, 'sale_price' => '99.1200']);
        $variant = app(ProductVariantManager::class)->save($this->actor, $product, ['size_id' => Size::factory()->create()->id]);
        $this->assertSame('99.1200', $variant->sale_price);
        $this->expectException(ValidationException::class);
        app(ProductVariantManager::class)->save($this->actor, $product, ['sale_price' => '1'], $variant);
    }

    public function test_sales_uses_shared_default_and_keeps_saved_price_and_explicit_discounts(): void
    {
        $variant = $this->variant();
        $this->savePrice($variant->product, ['has_fixed_selling_price' => true, 'sale_price' => '50.1234']);
        $branch = Location::create(['name' => 'Sales branch', 'type' => 'branch', 'is_active' => true]);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id,
            'quantity_on_hand' => '10', 'quantity_available' => '10', 'quantity_reserved' => '0']);
        $service = app(SalesOrderService::class);
        $order = $service->create($this->actor, ['location_id' => $branch->id,
            'items' => [['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => '2', 'discount_amount' => '2', 'tax_amount' => '3']]], (string) Str::uuid7());
        $this->assertSame('50.123400', $order->items->first()->unit_price);
        $this->assertSame('101.2468', $order->total_amount);
        $this->savePrice($variant->product->fresh(), ['sale_price' => '70']);
        DB::transaction(fn () => $service->validateForConfirmation($this->actor, $order->fresh()));
        $this->assertSame('50.123400', $order->fresh()->items->first()->unit_price);
    }

    public function test_sizes_and_colors_can_be_renamed_and_disabled_without_identity_changes(): void
    {
        $size = Size::factory()->create();
        $color = Color::factory()->create();
        $variant = ProductVariant::factory()->create(['size_id' => $size->id, 'color_id' => $color->id]);
        $before = $variant->only(['sku', 'barcode', 'size_id', 'color_id']);
        foreach ([$size, $color] as $record) {
            app(ProductCatalogManager::class)->save($this->actor, $record::class, ['name' => 'Changed '.$record->id, 'is_active' => false], $record);
            $this->assertFalse(app(ProductCatalogManager::class)->canDelete($record));
        }
        $this->assertSame($before, $variant->fresh()->only(array_keys($before)));
        $this->assertNotNull($variant->fresh()->size);
        $this->assertNotNull($variant->fresh()->color);
        $this->expectException(ValidationException::class);
        app(ProductCatalogManager::class)->delete($this->actor, $size);
    }

    public function test_inactive_attributes_are_rejected_for_new_variants(): void
    {
        $this->expectException(ValidationException::class);
        app(ProductVariantManager::class)->save($this->actor, Product::factory()->create(), ['color_id' => Color::factory()->create(['is_active' => false])->id]);
    }

    public function test_product_and_variant_pricing_pages_render_with_barcode_actions(): void
    {
        $variant = $this->variant();
        Livewire::test(EditProduct::class, ['record' => $variant->product->slug])->assertOk();
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])
            ->assertOk()->assertTableActionExists('printBarcode')->assertTableActionExists('barcodePreview');
    }

    public static function barcodeValues(): array
    {
        return [['4006381333931', 'EAN13'], ['4006381333932', 'C128B'], ['FOX-40-BLK', 'C128B'], ['0001234567890', 'C128B'],
            ['literal&#x41;', 'C128B'], ['literal&#65;', 'C128B'], [null, null], ['', null], ["bad\nvalue", null], ['لون', null]];
    }

    #[DataProvider('barcodeValues')]
    public function test_barcode_format_and_svg_preserve_exact_identifiers(?string $value, ?string $format): void
    {
        $barcode = app(VariantBarcode::class);
        $this->assertSame($format, $barcode->format($value));
        $svg = $barcode->svg($value);
        if ($format === null) {
            $this->assertNull($svg);
        } else {
            $this->assertStringContainsString('aria-label="'.e($value).'"', $svg);
            $this->assertStringContainsString('<rect x="11"', $svg);
            $this->assertStringContainsString('fill="white"', $svg);
        }
    }

    public function test_print_label_requires_catalog_access_and_never_exposes_costs(): void
    {
        $variant = $this->variant();
        $before = $variant->fresh()->getAttributes();
        $this->get(route('products.variants.barcode-label', $variant))->assertOk()->assertSee($variant->barcode)
            ->assertSee($variant->sku)->assertSee('<svg', false)->assertSee('@media print', false)->assertDontSee('cost_price');
        $this->assertSame($before, $variant->fresh()->getAttributes());
        Module::where('code', 'products')->update(['is_active' => false]);
        $this->get(route('products.variants.barcode-label', $variant))->assertForbidden();
    }

    public function test_svg_code128_modules_include_the_expected_start_checksum_and_stop(): void
    {
        $svg = app(VariantBarcode::class)->svg('ABC');
        preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)"/', $svg, $matches, PREG_SET_ORDER);
        $modules = str_repeat('0', 90);
        foreach ($matches as $bar) {
            $modules = substr_replace($modules, str_repeat('1', (int) $bar[2]), (int) $bar[1] - 11, (int) $bar[2]);
        }
        // Code set B: start 104, A=33, B=34, C=35; weighted checksum is 1.
        $expected = '11010010000'.'10100011000'.'10001011000'.'10001000110'.'11001101100'.'1100011101011';
        $this->assertSame($expected, substr($modules, 0, strlen($expected)));
    }

    public function test_literal_numeric_entities_are_encoded_as_literal_code128_characters(): void
    {
        foreach (['&#65;', '&#x41;'] as $value) {
            $svg = app(VariantBarcode::class)->svg($value);
            $this->assertNotSame(app(VariantBarcode::class)->svg('A'), $svg);
            $this->assertStringContainsString('viewBox="0 0 '.((strlen($value) + 2) * 11 + 13 + 22).' 80"', $svg);
        }
    }

    public function test_read_only_catalog_user_can_print_but_cannot_change_prices_or_attributes(): void
    {
        $variant = $this->variant();
        $location = Location::create(['name' => 'Read only branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'phase7-reader', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo(['products.view', 'products.update']);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->get(route('products.variants.barcode-label', $variant))->assertOk();
        $this->assertFalse($user->can('create', Size::class));
        $this->assertFalse($user->can('create', Color::class));
        $this->expectException(HttpException::class);
        app(ProductCatalogManager::class)->save($user, Product::class, ['name' => $variant->product->name,
            'has_fixed_selling_price' => true, 'sale_price' => '9'], $variant->product);
    }

    public function test_allocation_brand_product_and_color_changes_reset_dependent_state(): void
    {
        $brand = Brand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id]);
        $color = Color::factory()->create();
        $target = ProductVariant::factory()->create(['product_id' => $product->id, 'size_id' => Size::factory()->create()->id, 'color_id' => $color->id]);
        $source = app(UnallocatedVariantService::class)->ensure($this->actor, $product);
        $location = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        StockBalance::create(['variant_id' => $source->id, 'location_id' => $location->id, 'quantity_on_hand' => '24', 'quantity_available' => '24']);
        $this->assertSame([$product->id], SizeAllocationResource::eligibleProducts()->pluck('id')->all());
        $page = Livewire::test(CreateSizeAllocation::class)->fillForm(['brand_id' => $brand->id, 'product_id' => $product->id, 'location_id' => $location->id]);
        $page->assertOk()->set('data.brand_id', 'none')->assertSet('data.product_id', null)->assertSet('data.location_id', null)->assertSet('data.items', []);
        $page->set('data.brand_id', $brand->id)->set('data.product_id', $product->id)->assertSet('data.location_id', null);
        $this->assertSame([$target->id], array_column(SizeAllocationResource::grid($product->id, $color->id), 'variant_id'));
        $this->assertSame([], SizeAllocationResource::grid($product->id, 'none'));
    }

    public function test_allocation_form_rejects_over_allocation_before_saving(): void
    {
        $product = Product::factory()->create();
        $target = ProductVariant::factory()->create(['product_id' => $product->id, 'size_id' => Size::factory()->create()->id]);
        $source = app(UnallocatedVariantService::class)->ensure($this->actor, $product);
        $location = Location::create(['name' => 'Allocation warehouse', 'type' => 'warehouse', 'is_active' => true]);
        StockBalance::create(['variant_id' => $source->id, 'location_id' => $location->id, 'quantity_on_hand' => '24', 'quantity_available' => '24']);
        Livewire::test(CreateSizeAllocation::class)->fillForm(['product_id' => $product->id, 'location_id' => $location->id,
            'items' => [['variant_id' => $target->id, 'quantity' => '25']]])->call('create')->assertHasFormErrors(['items']);
        $this->assertDatabaseCount('inventory_size_allocations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inline_price_edit_uses_the_existing_mutation_service(): void
    {
        $variant = $this->variant();
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product, 'pageClass' => EditProduct::class])
            ->call('updateTableColumnState', 'sale_price', (string) $variant->id, '42.5678')->assertHasNoErrors();
        $this->assertSame('42.5678', $variant->fresh()->sale_price);
        $this->savePrice($variant->product, ['has_fixed_selling_price' => true, 'sale_price' => '99']);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $variant->product->fresh(), 'pageClass' => EditProduct::class])
            ->call('updateTableColumnState', 'sale_price', (string) $variant->id, '1');
        $this->assertSame('42.5678', $variant->fresh()->sale_price);
    }
}
