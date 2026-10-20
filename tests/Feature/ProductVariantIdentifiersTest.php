<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Products\Services\ProductVariantIdentifierGenerator;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductVariantIdentifiersTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'products')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('public');
        $user = $this->provisionTestSuperAdmin();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, null);
    }

    private function nikeProduct(): Product
    {
        return Product::factory()->create(['name' => 'Nike Air Max', 'brand_id' => Brand::factory()->create(['name' => 'Nike'])->id]);
    }

    private function assertEan(string $barcode): void
    {
        $this->assertMatchesRegularExpression('/^20[0-9]{11}$/', $barcode);
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += (int) $barcode[$index] * ($index % 2 === 0 ? 1 : 3);
        }
        $this->assertSame((string) ((10 - $sum % 10) % 10), $barcode[12]);
    }

    public function test_observer_generates_short_meaningful_sku_and_valid_barcode_before_insertion(): void
    {
        $product = $this->nikeProduct();
        $variant = ProductVariant::create(['product_id' => $product->id, 'color_id' => Color::factory()->create(['name' => 'Black'])->id, 'size_id' => Size::factory()->create(['name' => '42'])->id]);
        $this->assertSame('NK-AMX-BLK-42', $variant->sku);
        $this->assertEan($variant->barcode);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode]);
    }

    public function test_colliding_skus_receive_short_suffixes_and_barcodes_remain_unique(): void
    {
        $product = $this->nikeProduct();
        $first = ProductVariant::create(['product_id' => $product->id]);
        $second = ProductVariant::create(['product_id' => $product->id]);
        $this->assertSame('NK-AMX', $first->sku);
        $this->assertSame('NK-AMX-2', $second->sku);
        $this->assertNotSame($first->barcode, $second->barcode);
        $this->assertEan($first->barcode);
        $this->assertEan($second->barcode);
    }

    public function test_barcode_hash_collisions_are_resolved_without_overwriting_legacy_codes(): void
    {
        $product = $this->nikeProduct();
        $digits = '20'.str_pad((string) (hexdec(substr(hash('sha256', 'NK-AMX:0'), 0, 12)) % 10000000000), 10, '0', STR_PAD_LEFT);
        $barcode = $digits.app(ProductVariantIdentifierGenerator::class)->checkDigit($digits);
        $legacy = ProductVariant::create(['product_id' => $product->id, 'sku' => 'IMPORTED', 'barcode' => $barcode]);
        $generated = ProductVariant::create(['product_id' => $product->id]);
        $this->assertNotSame($barcode, $generated->barcode);
        $this->assertSame($barcode, $legacy->fresh()->barcode);
        $this->assertEan($generated->barcode);
    }

    public function test_arabic_names_have_short_deterministic_ascii_fallbacks(): void
    {
        $name = "\u{062D}\u{0630}\u{0627}\u{0621} \u{0631}\u{064A}\u{0627}\u{0636}\u{064A}";
        $product = Product::factory()->create(['name' => $name, 'brand_id' => null]);
        $first = ProductVariant::create(['product_id' => $product->id]);
        $second = ProductVariant::create(['product_id' => $product->id]);
        $this->assertMatchesRegularExpression('/^[A-Z0-9-]{1,24}$/', $first->sku);
        $this->assertSame($first->sku.'-2', $second->sku);
        $this->assertEan($first->barcode);
    }

    public function test_product_brand_dimensions_and_price_edits_preserve_identifiers(): void
    {
        $product = $this->nikeProduct();
        $color = Color::factory()->create(['name' => 'Black']);
        $size = Size::factory()->create(['name' => '42']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id]);
        $identifiers = [$variant->sku, $variant->barcode];
        $product->update(['name' => 'Renamed product']);
        $product->brand->update(['name' => 'Renamed brand']);
        $color->update(['name' => 'White']);
        $size->update(['name' => '43']);
        $variant->update(['sale_price' => '99.0000']);
        $this->assertSame($identifiers, [$variant->fresh()->sku, $variant->fresh()->barcode]);
        $legacy = ProductVariant::create(['product_id' => $product->id, 'sku' => 'Legacy-SKU', 'barcode' => '001234']);
        $legacy->update(['sale_price' => '1']);
        $this->assertSame('Legacy-SKU', $legacy->fresh()->sku);
        $this->assertSame('001234', $legacy->fresh()->barcode);
    }

    public function test_lock_is_released_when_variant_creation_rolls_back(): void
    {
        $product = $this->nikeProduct();
        try {
            DB::transaction(function () use ($product): void {
                ProductVariant::create(['product_id' => $product->id]);
                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback', $exception->getMessage());
        }
        $name = 'variant-identifiers:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        $this->assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS free', [$name])->free);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_two_concurrent_processes_generate_unique_identifiers(): void
    {
        $database = DB::connection()->getDatabaseName();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_', $database);
        $product = $this->nikeProduct();
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = Illuminate\Support\Facades\DB::connection();
if (!$app->environment('testing') || !str_starts_with($c->getDatabaseName(), 'codex_public_ids_test_') || $c->selectOne('SELECT DATABASE() AS db')->db !== $c->getDatabaseName()) { exit(2); }
$v = App\Modules\Products\Models\ProductVariant::create(['product_id' => (int) getenv('VARIANT_TEST_PRODUCT_ID')]);
echo json_encode(['sku' => $v->sku, 'barcode' => $v->barcode]);
PHP;
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'VARIANT_TEST_PRODUCT_ID' => (string) $product->id];
        $workers = [new Process([PHP_BINARY, '-r', $worker], base_path(), $environment), new Process([PHP_BINARY, '-r', $worker], base_path(), $environment)];
        try {
            foreach ($workers as $process) {
                $process->setTimeout(50)->start();
            }
            $identifiers = [];
            foreach ($workers as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $identifiers[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertNotSame($identifiers[0]['sku'], $identifiers[1]['sku']);
            $this->assertNotSame($identifiers[0]['barcode'], $identifiers[1]['barcode']);
            $this->assertEan($identifiers[0]['barcode']);
            $this->assertEan($identifiers[1]['barcode']);
            $this->assertDatabaseCount('product_variants', 2);
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_create_has_no_identifier_inputs_and_edit_displays_readonly_identifiers(): void
    {
        $page = Livewire::test(CreateProduct::class)->fillForm(['variants' => [['sale_price' => '1', 'is_active' => true]], 'images' => [['sort_order' => 0]]]);
        $key = array_key_first($page->get('data.variants'));
        $imageKey = array_key_first($page->get('data.images'));
        $page->assertFormFieldDoesNotExist('variants.'.$key.'.sku')->assertFormFieldDoesNotExist('variants.'.$key.'.barcode')
            ->assertFormFieldDoesNotExist('images.'.$imageKey.'.variant_key')->assertFormFieldDoesNotExist('images.'.$imageKey.'.product_variant_id');
        $product = $this->nikeProduct();
        $variant = ProductVariant::create(['product_id' => $product->id]);
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->mountAction(TestAction::make('edit')->table($variant))->assertSee($variant->sku)->assertSee($variant->barcode)
            ->assertFormFieldDoesNotExist('sku')->assertFormFieldDoesNotExist('barcode');
        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])->assertSee($variant->sku)->assertSee($variant->barcode);
    }

    public function test_edit_assigns_images_using_color_size_labels_and_rejects_foreign_variants(): void
    {
        $product = $this->nikeProduct();
        $variant = ProductVariant::create(['product_id' => $product->id, 'color_id' => Color::factory()->create(['name' => 'Black'])->id, 'size_id' => Size::factory()->create(['name' => '42'])->id]);
        $upload = UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $image = app(ProductImageManager::class)->save(auth()->user(), $product, ['upload' => $upload]);
        $this->assertNull($image->product_variant_id);
        $this->assertSame('Black — 42', $variant->imageLabel());
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->mountAction(TestAction::make('edit')->table($image))->assertFormFieldExists('product_variant_id', fn (Select $field): bool => $field->getOptions() === [$variant->id => $variant->imageLabel()])->unmountAction()
            ->callAction(TestAction::make('edit')->table($image), ['product_variant_id' => $variant->id, 'sort_order' => 0])->assertHasNoFormErrors();
        $this->assertSame($variant->id, $image->fresh()->product_variant_id);
        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])->assertSee('Black — 42');
        $foreign = ProductVariant::factory()->create();
        Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callAction(TestAction::make('edit')->table($image), ['product_variant_id' => $foreign->id, 'sort_order' => 0])->assertHasFormErrors();
        $this->assertSame($variant->id, $image->fresh()->product_variant_id);
        Storage::disk('public')->assertExists($image->path);
    }
}
