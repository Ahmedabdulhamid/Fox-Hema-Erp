<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductCreationManager;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Taxes\Models\Tax;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductCreationTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'taxes', 'discounts'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('public');
        $user = $this->provisionTestSuperAdmin();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, null);
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('product.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    private function create(array $data): Product
    {
        return app(ProductCreationManager::class)->create(auth()->user(), ['name' => 'Complete product', ...$data]);
    }

    public function test_create_tabs_are_available_in_both_languages_and_basic_creation_still_works(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::test(CreateProduct::class);
            foreach (['Product Information', 'Variants', 'Taxes', 'Discounts', 'Images'] as $label) {
                $page->assertSee(__($label));
            }
            $page->assertFormFieldExists('name')->assertFormFieldExists('description')
                ->assertFormFieldExists('variants')->assertFormFieldExists('images');
        }
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Basic product'])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Basic product')->sole();
        $this->assertFalse($product->is_active);
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_filament_creates_variants_and_generates_missing_identifiers(): void
    {
        $size = Size::factory()->create();
        $color = Color::factory()->create();
        Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'With variants',
            'variants' => [
                ['size_id' => $size->id, 'sale_price' => '12.5000', 'cost_price' => '5.0000', 'is_active' => true],
                ['color_id' => $color->id, 'sale_price' => '15.0000'],
            ],
        ])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'With variants')->sole();
        $this->assertSame(2, $product->variants()->count());
        $generated = $product->variants()->where('size_id', $size->id)->sole();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $generated->sku);
        $this->assertMatchesRegularExpression('/^20[0-9]{11}$/', $generated->barcode);
        $this->assertSame('12.5000', $generated->sale_price);
        $this->assertSame('5.0000', $generated->cost_price);
        $this->assertSame(2, $product->variants()->distinct()->count('sku'));
        $this->assertSame(2, $product->variants()->distinct()->count('barcode'));
    }

    public static function assignmentKinds(): array
    {
        return ['taxes' => ['taxes', 'tax_ids', Tax::class], 'discounts' => ['discounts', 'discount_ids', Discount::class]];
    }

    #[DataProvider('assignmentKinds')]
    public function test_filament_assigns_definitions_during_creation(string $kind, string $field, string $model): void
    {
        $definition = $model::factory()->create();
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Assigned product', $field => [$definition->id]])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Assigned product')->sole();
        $this->assertSame([$definition->id], $product->{$kind}()->pluck($kind.'.id')->all());
    }

    public function test_filament_uploads_multiple_images_and_enforces_one_primary_per_scope(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'With images',
            'images' => [
                ['upload' => [$this->upload()], 'alt_text' => 'Front', 'sort_order' => 0, 'is_primary' => true],
                ['upload' => [$this->upload()], 'alt_text' => 'Back', 'sort_order' => 1, 'is_primary' => true],
            ],
        ])->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'With images')->sole();
        $this->assertSame(2, $product->images()->count());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertSame('Back', $product->images()->where('is_primary', true)->sole()->alt_text);
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
    }

    public function test_filament_creates_everything_together_and_edit_preserves_related_records(): void
    {
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $page = Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'Everything', 'description' => '<p>Details</p>', 'status' => 'active',
            'variants' => [['sale_price' => '25.0000', 'is_active' => true]],
            'tax_ids' => [$tax->id], 'discount_ids' => [$discount->id],
        ]);
        $page->fillForm(['images' => [['upload' => [$this->upload()], 'sort_order' => 0, 'is_primary' => true]]])
            ->call('create')->assertHasNoFormErrors();
        $product = Product::where('name', 'Everything')->sole();
        $variant = $product->variants()->sole();
        $this->assertNull($product->images()->sole()->product_variant_id);
        $this->assertSame(1, $product->taxes()->count());
        $this->assertSame(1, $product->discounts()->count());
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Edited', 'is_active' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame(1, $product->images()->count());
        $this->assertSame(1, $product->variants()->count());
        $this->assertSame(1, $product->taxes()->count());
        $this->assertSame(1, $product->discounts()->count());
    }

    #[DataProvider('assignmentKinds')]
    public function test_inactive_modules_reject_submitted_assignments_without_creating_product(string $kind, string $field, string $model): void
    {
        $definition = $model::factory()->create();
        Module::where('code', $kind)->update(['is_active' => false]);
        Livewire::test(CreateProduct::class)->assertFormFieldIsHidden($field);
        try {
            $this->create([$field => [$definition->id]]);
            $this->fail('Inactive assignment was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('assignmentKinds')]
    public function test_ineligible_and_future_definitions_are_rejected(string $kind, string $field, string $model): void
    {
        $start = $kind === 'taxes' ? 'effective_from' : 'starts_at';
        foreach ([['is_active' => false], [$start => now()->addDays(2)]] as $attributes) {
            $definition = $model::factory()->create($attributes);
            Livewire::test(CreateProduct::class)->fillForm(['name' => 'Invalid assignment'])->set('data.'.$field, [$definition->id])
                ->call('create')->assertHasFormErrors();
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_order_discount_and_forged_image_variant_are_rejected(): void
    {
        $discount = Discount::factory()->create(['scope' => 'order']);
        foreach ([['discount_ids' => [$discount->id]], ['images' => [['upload' => [$this->upload()], 'variant_key' => 'foreign']]]] as $data) {
            try {
                $this->create($data);
                $this->fail('Invalid related data was accepted.');
            } catch (ValidationException|HttpException $exception) {
                $this->assertTrue($exception instanceof ValidationException || $exception->getStatusCode() === 403);
            }
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_creation_rejects_manually_submitted_skus_and_barcodes(): void
    {
        $existing = ProductVariant::factory()->create(['sku' => 'EXISTING', 'barcode' => 'KNOWN']);
        foreach ([['sku' => 'EXISTING'], ['barcode' => 'KNOWN']] as $values) {
            Livewire::test(CreateProduct::class)->fillForm(['name' => 'Duplicate', 'variants' => [$values]])
                ->call('create')->assertHasFormErrors();
            $this->assertDatabaseMissing('products', ['name' => 'Duplicate']);
        }
        $size = Size::factory()->create();
        foreach (['sku', 'barcode'] as $field) {
            try {
                $this->create(['variants' => [['size_id' => $size->id, $field => 'REPEATED'], ['color_id' => Color::factory()->create()->id, $field => 'REPEATED']]]);
                $this->fail('Duplicate identifiers were accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('variants.0.'.$field, $exception->errors());
            }
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertNotNull($existing->fresh());
    }

    public function test_duplicate_dimensions_and_mixed_simple_variants_are_rejected(): void
    {
        $size = Size::factory()->create();
        foreach ([[['size_id' => $size->id], ['size_id' => $size->id]], [[], ['size_id' => $size->id]]] as $variants) {
            try {
                $this->create(['variants' => $variants]);
                $this->fail('Invalid combinations were accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_invalid_product_variant_and_image_data_leave_no_records_or_files(): void
    {
        foreach ([['name' => ''], ['variants' => [['sale_price' => '-1']]], ['images' => [['upload' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')]]]] as $data) {
            try {
                $this->create($data);
                $this->fail('Invalid data was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_late_image_failure_rolls_back_all_records_and_cleans_previously_stored_files(): void
    {
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $this->partialMock(ProductImageManager::class, function ($mock): void {
            $mock->shouldReceive('save')->once()->passthru();
            $mock->shouldReceive('save')->once()->andThrow(new RuntimeException('Storage failed.'));
        });
        try {
            $this->create(['variants' => [['sale_price' => '10']], 'tax_ids' => [$tax->id], 'discount_ids' => [$discount->id], 'images' => [['upload' => [$this->upload()]], ['upload' => [$this->upload()]]]]);
            $this->fail('Storage failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage failed.', $exception->getMessage());
        }
        foreach (['products', 'product_variants', 'product_images', 'product_taxes', 'discount_products', 'product_image_cleanup_tasks'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_parent_transaction_rollback_removes_product_and_uploaded_files(): void
    {
        try {
            DB::transaction(function (): void {
                $product = $this->create(['images' => [['upload' => [$this->upload()]]]]);
                Storage::disk('public')->assertExists($product->images()->sole()->path);
                throw new RuntimeException('Parent transaction failed.');
            });
            $this->fail('Parent failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Parent transaction failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_failed_storage_cleanup_remains_queued_and_can_be_retried(): void
    {
        $manager = app(ProductImageManager::class);
        $this->partialMock(ProductImageManager::class, function ($mock): void {
            $mock->shouldReceive('save')->once()->passthru();
            $mock->shouldReceive('save')->once()->andThrow(new RuntimeException('Storage failed.'));
            $mock->shouldReceive('cleanup')->once()->andReturn(false);
        });
        try {
            $this->create(['images' => [['upload' => [$this->upload()]], ['upload' => [$this->upload()]]]]);
            $this->fail('Storage failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage failed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertDatabaseCount('product_image_cleanup_tasks', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
        app()->instance(ProductImageManager::class, $manager);
        $this->artisan('products:cleanup-images')->assertSuccessful();
        $this->assertDatabaseCount('product_image_cleanup_tasks', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public static function protectedOperations(): array
    {
        return [
            'tax assignment' => ['tax_ids', 'products.assign_taxes'],
            'tax module assignment' => ['tax_ids', 'taxes.assign'],
            'discount assignment' => ['discount_ids', 'products.assign_discounts'],
            'discount module assignment' => ['discount_ids', 'discounts.assign'],
            'variants' => ['variants', 'products.variants.create'],
            'sale price' => ['variants', 'products.update_prices'],
            'reference cost' => ['variants', 'products.update_reference_cost'],
            'reference cost visibility' => ['variants', 'products.view_reference_cost'],
            'images' => ['images', 'products.images.create'],
        ];
    }

    #[DataProvider('protectedOperations')]
    public function test_scoped_creators_cannot_submit_related_data_without_its_permissions(string $field, string $missingPermission): void
    {
        $location = Location::create(['name' => 'Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'creator', 'guard_name' => 'web', 'location_id' => $location->id]);
        $permissions = ['products.view', 'products.create', 'products.assign_taxes', 'products.assign_discounts', 'taxes.assign', 'discounts.assign', 'products.variants.create', 'products.update_prices', 'products.view_reference_cost', 'products.update_reference_cost', 'products.images.create'];
        $role->givePermissionTo(array_values(array_diff($permissions, [$missingPermission])));
        $user->assignRole($role);
        $this->actingAs($user);
        $data = match ($field) {
            'tax_ids' => [Tax::factory()->create()->id],
            'discount_ids' => [Discount::factory()->create()->id],
            'variants' => [['sale_price' => '1', 'cost_price' => '1']],
            'images' => [['upload' => [$this->upload()]]],
        };
        try {
            $this->create([$field => $data]);
            $this->fail('Unauthorized related data was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }
}
