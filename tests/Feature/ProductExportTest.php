<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Jobs\RunProductExport;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductExport as ExportRecord;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductExport;
use App\Modules\Products\Services\ProductImportEmployeeFormat;
use App\Modules\Products\Services\ProductImportManager;
use App\Modules\Products\Services\ProductImportProcessor;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Taxes\Models\Tax;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;
use ZipArchive;

class ProductExportTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private string $directory;

    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'taxes', 'discounts'])->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->directory = storage_path('framework/testing/export-'.Str::uuid());
        File::makeDirectory($this->directory, 0700, true);
        config(['product-import.root' => $this->directory.'/imports', 'product-import.free_disk_bytes' => 0]);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) {
            @unlink($path);
            @unlink($path.'.ids');
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** @return array<string, list<list<mixed>>> */
    private function sheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $result = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $result[$sheet->getName()][] = $row->toArray();
                }
            }
        } finally {
            $reader->close();
        }

        return $result;
    }

    private function export(?array $ids = null, string $locale = 'en'): array
    {
        $path = $this->directory.'/export.zip';
        $query = ProductResource::getEloquentQuery();
        if ($ids !== null) {
            $query->whereKey($ids);
        }
        app(ProductExport::class)->write($this->actor, $query, $path, $locale);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $files[$name] = $zip->getFromIndex($index);
            File::ensureDirectoryExists(dirname($this->directory.'/'.$name));
            file_put_contents($this->directory.'/'.$name, $files[$name]);
        }
        $zip->close();

        return $files;
    }

    public static function locales(): array
    {
        return [['ar'], ['en']];
    }

    #[DataProvider('locales')]
    public function test_export_uses_the_import_template_and_reimports_all_supported_relationships_as_new_records(string $locale): void
    {
        $brand = Brand::factory()->create(['name' => 'Nike']);
        $parent = Category::factory()->create(['name' => 'Clothing']);
        $category = Category::factory()->create(['name' => 'Shoes', 'parent_id' => $parent->id]);
        $color = Color::factory()->create(['name' => 'Black']);
        $size = Size::factory()->create(['name' => '42']);
        $tax = Tax::factory()->create(['code' => 'VAT14']);
        $discount = Discount::factory()->create(['code' => 'OFF10']);
        $product = Product::factory()->create(['name' => '=Shoes & حذاء', 'description' => '<p>Details</p>', 'brand_id' => $brand->id, 'category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id, 'sale_price' => '12345678901234.1234', 'cost_price' => '75.4321']);
        $product->taxes()->attach($tax);
        $product->discounts()->attach($discount);
        $upload = UploadedFile::fake()->image('front.png');
        $bytes = file_get_contents($upload->getRealPath());
        foreach ([null, $variant->id] as $target) {
            $path = 'products/'.$product->id.'/'.($target ?? 'cover').'/front.png';
            Storage::disk('public')->put($path, $bytes);
            ProductImage::factory()->create(['product_id' => $product->id, 'product_variant_id' => $target, 'path' => $path, 'is_primary' => true, 'sort_order' => 3, 'alt_text' => 'Front حذاء']);
        }
        $fixed = Product::factory()->create(['name' => 'Fixed', 'has_fixed_selling_price' => true, 'sale_price' => '250.5000']);
        ProductVariant::factory()->create(['product_id' => $fixed->id]);
        $files = $this->export(locale: $locale);
        $workbook = $this->directory.'/part-0001/products.xlsx';
        $sheets = $this->sheets($workbook);
        $this->assertSame(['Instructions', 'Products', 'Variants', 'ProductTaxes', 'ProductDiscounts', 'Images', 'References'], array_keys($sheets));
        foreach (array_keys(ProductImportEmployeeFormat::COLUMNS) as $sheet) {
            $this->assertSame(app(ProductImportEmployeeFormat::class)->headers($sheet, $locale), $sheets[$sheet][0]);
        }
        $this->assertSame('Clothing / Shoes', $sheets['Products'][1][3]);
        $this->assertSame('12345678901234.1234', $sheets['Variants'][1][3]);
        $this->assertSame('75.4321', $sheets['Variants'][1][4]);
        $this->assertSame('', $sheets['Variants'][2][3]);
        $this->assertSame('250.5000', $sheets['Products'][2][6]);
        $this->assertNotSame($sheets['Images'][1][3], $sheets['Images'][2][3]);
        $uploads = [];
        foreach (array_slice($sheets['Images'], 1) as $row) {
            $this->assertSame($bytes, $files['part-0001/images/'.$row[3]]);
            $uploads[] = new UploadedFile($this->directory.'/part-0001/images/'.$row[3], $row[3], test: true);
        }
        $import = app(ProductImportManager::class)->submit($this->actor, new UploadedFile($workbook, 'products.xlsx', test: true), $uploads);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('ready', $import->fresh()->status, json_encode($import->fresh()->errors));
        foreach ($import->entries as $entry) {
            $this->assertSame('valid', $entry->status, json_encode($entry->errors));
        }
        app(ProductImportManager::class)->start($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 4);
        $copy = Product::where('name', $product->name)->whereKeyNot($product->id)->sole();
        $this->assertSame($product->default_sku, $product->fresh()->default_sku);
        $this->assertNotSame($product->default_sku, $copy->default_sku);
        $this->assertSame($brand->id, $copy->brand_id);
        $this->assertSame($category->id, $copy->category_id);
        $this->assertSame($color->id, $copy->variants()->sole()->color_id);
        $this->assertSame('75.4321', $copy->variants()->sole()->cost_price);
        $this->assertSame([$tax->id], $copy->taxes()->pluck('taxes.id')->all());
        $this->assertSame([$discount->id], $copy->discounts()->pluck('discounts.id')->all());
        $this->assertSame(1, $copy->images()->whereNull('product_variant_id')->count());
        $this->assertSame(1, $copy->images()->where('product_variant_id', $copy->variants()->sole()->id)->count());
        $this->assertSame([3, 3], $copy->images()->pluck('sort_order')->all());
    }

    public function test_missing_images_are_omitted_and_reported_without_broken_references(): void
    {
        $product = Product::factory()->create();
        ProductImage::factory()->create(['product_id' => $product->id]);
        $files = $this->export();
        $sheets = $this->sheets($this->directory.'/part-0001/products.xlsx');
        $this->assertCount(1, $files);
        $this->assertCount(1, $sheets['Images']);
        $this->assertContains([__('product_export.missing_images', ['count' => 1], 'en')], $sheets['Instructions']);
    }

    public function test_large_exports_split_at_import_limits_and_duplicate_names_remain_separate(): void
    {
        config(['product-import.products' => 2]);
        Product::factory()->create(['name' => 'Repeated']);
        Product::factory()->create(['name' => ' repeated ']);
        Product::factory()->count(3)->create();
        $files = $this->export();
        $this->assertCount(3, $files);
        $count = 0;
        foreach (array_keys($files) as $path) {
            $sheets = $this->sheets($this->directory.'/'.$path);
            $this->assertLessThanOrEqual(3, count($sheets['Products']));
            $count += count($sheets['Products']) - 1;
        }
        $this->assertSame(5, $count);
    }

    private function employee(array $permissions): User
    {
        $location = Location::create(['name' => 'Export branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $location->id);
        $actor->givePermissionTo($permissions);
        $this->actor = $actor->fresh();
        $this->actingAs($this->actor);
        RequestAuthorizationFacts::forget();

        return $this->actor;
    }

    public function test_view_only_employee_can_export_without_import_permission_but_cannot_see_costs(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => '999.1234']);
        $this->employee(['products.view']);
        $this->export();
        $sheets = $this->sheets($this->directory.'/part-0001/products.xlsx');
        $this->assertSame('', $sheets['Variants'][1][4]);
        $this->assertContains([__('product_export.costs_omitted', [], 'en')], $sheets['Instructions']);
        Livewire::test(ListProducts::class)->assertActionVisible(TestAction::make('exportAll')->table())
            ->callAction(TestAction::make('exportAll')->table())->assertHasNoActionErrors();
        $record = ExportRecord::sole();
        $this->assertSame('pending', $record->status);
        Queue::assertPushed(RunProductExport::class, fn (RunProductExport $job): bool => $job->queue === 'product-exports' && $job->connection === 'product_exports_database');
        $this->assertFileDoesNotExist(app(ProductExport::class)->archivePath($record->uuid));
        app(ProductExport::class)->run($record->id);
        $this->assertSame('completed', $record->fresh()->status);
        $history = Livewire::test(ListProducts::class)->mountAction(TestAction::make('exportHistory')->table())
            ->assertActionMounted(TestAction::make('exportHistory')->table());
        $this->assertStringContainsString(__('product_export.statuses.completed'), $history->instance()->getMountedAction()->getModalContent()->render());
        $url = app(ProductExport::class)->url($record);
        $this->get($url)->assertDownload('products.zip')->assertHeader('Content-Type', 'application/zip');
    }

    public function test_bulk_action_exports_only_selected_records_allowed_by_the_record_policy(): void
    {
        $products = Product::factory()->count(3)->create();
        $this->employee(['products.view']);
        Gate::before(fn (User $actor, string $ability, array $arguments): ?bool => $ability === 'view'
            && ($arguments[0] ?? null) instanceof Product && $arguments[0]->id === $products[1]->id ? false : null);
        $this->export($products->take(2)->modelKeys());
        $sheets = $this->sheets($this->directory.'/part-0001/products.xlsx');
        $this->assertCount(2, $sheets['Products']);
        $this->assertSame($products[0]->name, $sheets['Products'][1][0]);
        Livewire::test(ListProducts::class)->selectTableRecords([$products[0]->id])
            ->callAction(TestAction::make('exportSelected')->table()->bulk())->assertHasNoActionErrors();
        $record = ExportRecord::sole();
        $this->assertSame([$products[0]->id], DB::table('product_export_products')->where('export_id', $record->id)->pluck('product_id')->all());
        Queue::assertPushed(RunProductExport::class);
    }

    public function test_export_rejects_inactive_or_unassigned_location_context(): void
    {
        $this->employee(['products.view']);
        setPermissionsTeamId(Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true])->id);
        RequestAuthorizationFacts::forget();
        $this->expectException(HttpException::class);
        $this->export();
    }

    public function test_export_rejects_employees_without_product_visibility(): void
    {
        $this->employee([]);
        $this->expectException(HttpException::class);
        $this->export();
    }

    public function test_unrepresentable_references_fail_instead_of_dropping_relationships(): void
    {
        $brand = Brand::factory()->create(['is_active' => false]);
        Product::factory()->create(['brand_id' => $brand->id]);
        $this->expectException(ValidationException::class);
        $this->export();
    }

    private function downloadUrl(): string
    {
        $record = app(ProductExport::class)->request($this->actor, ProductResource::getEloquentQuery());
        app(ProductExport::class)->run($record->id);
        $url = app(ProductExport::class)->url($record);
        $this->archives[] = app(ProductExport::class)->archivePath(basename(parse_url($url, PHP_URL_PATH)));

        return $url;
    }

    public function test_download_is_signed_bound_to_its_actor_location_and_current_permissions(): void
    {
        Product::factory()->create();
        $this->employee(['products.view', 'products.view_reference_cost']);
        $url = $this->downloadUrl();
        $owner = $this->actor;
        $location = getPermissionsTeamId();
        $this->get($url.'tampered')->assertForbidden();
        $this->employee(['products.view', 'products.view_reference_cost']);
        $this->get($url)->assertNotFound();
        $this->actingAs($owner);
        app(LocationContext::class)->setFor($owner, $location);
        $owner->revokePermissionTo('products.view_reference_cost');
        RequestAuthorizationFacts::forget();
        $this->get($url)->assertForbidden();
    }

    public function test_download_rechecks_record_visibility_and_expires(): void
    {
        $product = Product::factory()->create();
        $this->employee(['products.view']);
        $url = $this->downloadUrl();
        Gate::before(fn (User $actor, string $ability, array $arguments): ?bool => $ability === 'view'
            && ($arguments[0] ?? null) instanceof Product && $arguments[0]->id === $product->id ? false : null);
        $this->get($url)->assertForbidden();
        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_export_eager_loads_relationships_across_multiple_product_chunks(): void
    {
        $brand = Brand::factory()->create();
        foreach (Product::factory()->count(26)->create(['brand_id' => $brand->id]) as $product) {
            ProductVariant::factory()->create(['product_id' => $product->id]);
        }
        Model::preventLazyLoading();
        try {
            $this->export();
            $this->assertCount(27, $this->sheets($this->directory.'/part-0001/products.xlsx')['Products']);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_unallocated_and_regular_variants_round_trip_without_losing_identity_prices_or_images(): void
    {
        $product = Product::factory()->create(['name' => 'DDS-ASR']);
        $color = Color::factory()->create(['name' => 'Brown']);
        $size = Size::factory()->create(['name' => 'XS']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'color_id' => $color->id, 'size_id' => $size->id]);
        $unallocated = app(UnallocatedVariantService::class)->ensure($this->actor, $product);
        $unallocated->forceFill(['sale_price' => '12.3456', 'cost_price' => '4.5678', 'is_active' => false])->save();
        $upload = UploadedFile::fake()->image('unallocated.png');
        Storage::disk('public')->put('products/'.$product->id.'/unallocated.png', file_get_contents($upload->getRealPath()));
        ProductImage::factory()->create(['product_id' => $product->id, 'product_variant_id' => $unallocated->id,
            'path' => 'products/'.$product->id.'/unallocated.png', 'is_primary' => true]);
        $this->export();
        $workbook = $this->directory.'/part-0001/products.xlsx';
        $sheets = $this->sheets($workbook);
        $this->assertCount(3, $sheets['Variants']);
        $this->assertSame(['', '', '12.3456', '4.5678'], array_slice($sheets['Variants'][2], 1, 4));
        $import = app(ProductImportManager::class)->submit($this->actor, new UploadedFile($workbook, 'products.xlsx', test: true),
            [new UploadedFile($this->directory.'/part-0001/images/unallocated.png', 'unallocated.png', test: true)]);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('valid', $import->entries()->sole()->status, json_encode($import->entries()->sole()->errors));
        app(ProductImportManager::class)->start($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status, json_encode($import->entries()->pluck('errors')->all()));
        $copy = Product::whereKeyNot($product->id)->sole();
        $this->assertSame(2, $copy->variants()->count());
        $copyUnallocated = $copy->variants()->where('unallocated_product_id', $copy->id)->sole();
        $this->assertNull($copyUnallocated->color_id);
        $this->assertNull($copyUnallocated->size_id);
        $this->assertSame('12.3456', $copyUnallocated->sale_price);
        $this->assertSame('4.5678', $copyUnallocated->cost_price);
        $this->assertFalse($copyUnallocated->is_active);
        $this->assertSame($copyUnallocated->id, $copy->images()->sole()->product_variant_id);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_queued_exports_retry_safely_and_completed_jobs_are_idempotent(): void
    {
        $brand = Brand::factory()->create(['is_active' => false]);
        Product::factory()->create(['brand_id' => $brand->id]);
        $service = app(ProductExport::class);
        $export = $service->request($this->actor, ProductResource::getEloquentQuery());
        try {
            $service->run($export->id);
            $this->fail('Invalid references must fail the export.');
        } catch (ValidationException $exception) {
            $this->assertSame('failed', $export->fresh()->status);
            $this->assertFileDoesNotExist($service->archivePath($export->uuid));
        }
        $brand->update(['is_active' => true]);
        $service->run($export->id);
        $this->assertSame('completed', $export->fresh()->status);
        $hash = hash_file('sha256', $service->archivePath($export->uuid));
        $service->run($export->id);
        $this->assertSame($hash, hash_file('sha256', $service->archivePath($export->uuid)));
        $this->assertCount(2, Storage::disk('local')->allFiles('product-exports'));
    }

    public function test_worker_rechecks_permissions_and_restores_its_previous_actor_context(): void
    {
        $employee = $this->employee(['products.view']);
        $service = app(ProductExport::class);
        $export = $service->request($employee, ProductResource::getEloquentQuery());
        $team = getPermissionsTeamId();
        $employee->revokePermissionTo('products.view');
        RequestAuthorizationFacts::forget();
        try {
            $service->run($export->id);
            $this->fail('Revoked permissions must stop the export.');
        } catch (HttpException $exception) {
            $this->assertSame('failed', $export->fresh()->status);
            $this->assertSame($team, getPermissionsTeamId());
            $this->assertSame($employee->id, auth()->id());
        }
    }
}
