<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductImportEmployeeFormat;
use App\Modules\Products\Services\ProductImportEmployeeTemplate;
use App\Modules\Products\Services\ProductImportManager;
use App\Modules\Products\Services\ProductImportProcessor;
use App\Modules\Products\Services\ProductImportProductIdentifiers;
use App\Modules\Products\Services\ProductImportRecovery;
use App\Modules\Products\Services\ProductImportReferenceResolver;
use App\Modules\Products\Services\ProductImportWorkbook;
use App\Modules\Taxes\Models\Tax;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;
use ZipArchive;

class ProductImportEmployeeTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Color $color;

    private Size $size;

    private Brand $brand;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'taxes', 'discounts'])->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Queue::fake();
        Storage::set('public', Storage::fake('employee-import-public-'.Str::uuid()));
        config(['product-import.root' => storage_path('framework/testing/employee-import-'.Str::uuid()), 'product-import.free_disk_bytes' => 0]);
        $this->brand = Brand::factory()->create(['name' => 'Nike']);
        $this->category = Category::factory()->create(['name' => 'Shoes']);
        $this->color = Color::factory()->create(['name' => 'Black']);
        $this->size = Size::factory()->create(['name' => '42']);
    }

    private function workbook(array $data, string $locale = 'ar'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employee-workbook-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Instructions');
        $writer->addRow(Row::fromValues([ProductImportEmployeeFormat::VERSION.'-'.$locale]));
        foreach (ProductImportEmployeeFormat::COLUMNS as $sheet => $fields) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
            $writer->addRow(Row::fromValues(app(ProductImportEmployeeFormat::class)->headers($sheet, $locale)));
            foreach ($data[$sheet] ?? [] as $values) {
                $writer->addRow(Row::fromValues(array_map(fn (string $field): mixed => $values[$field] ?? '', $fields)));
            }
        }
        $resolver = app(ProductImportReferenceResolver::class);
        $lists = [];
        $bindings = [];
        foreach (ProductImportEmployeeTemplate::LISTS as $list => $kind) {
            if (isset(ProductImportReferenceResolver::MODELS[$kind])) {
                $options = $resolver->options($this->actor, $kind);
                $lists[$list] = array_values($options);
                $bindings[$kind] = array_keys($options);
            } else {
                $lists[$list] = array_values(__('product_import.employee_values.'.$kind, [], $locale));
            }
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('References');
        $writer->addRow(Row::fromValues([...array_keys($lists), ...array_map(fn (string $kind): string => $kind.'_binding', array_keys($bindings))]));
        for ($index = 0; $index < max([1, ...array_map('count', array_values($lists))]); $index++) {
            $writer->addRow(Row::fromValues([...array_values(array_map(fn (array $values): string => $values[$index] ?? '', $lists)), ...array_values(array_map(fn (array $values): string => $values[$index] ?? '', $bindings))]));
        }
        $writer->close();
        $upload = UploadedFile::fake()->createWithContent('employee.xlsx', file_get_contents($path));
        unlink($path);

        return $upload;
    }

    private function image(string $name, int $width = 1): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, 1);
    }

    private function fixture(string $name = 'حذاء فاخر', array $replace = []): array
    {
        return array_replace(['Products' => [['name' => $name, 'brand' => 'Nike', 'category' => 'Shoes']],
            'Variants' => [['product_name' => $name, 'color' => 'Black', 'size' => '42', 'sale_price' => '1500', 'variant_status' => 'نشط']]], $replace);
    }

    private function prepare(array $data, array $images = [], string $locale = 'ar'): ProductImport
    {
        $import = app(ProductImportManager::class)->submit($this->actor, $this->workbook($data, $locale), $images);
        app(ProductImportProcessor::class)->run($import->id);

        return $import->fresh();
    }

    private function finish(ProductImport $import): void
    {
        app(ProductImportManager::class)->start($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);
    }

    public function test_localized_templates_have_dynamic_dropdowns_and_three_product_examples_without_identifiers(): void
    {
        Size::factory()->create(['name' => '43']);
        Color::factory()->create(['name' => 'White']);
        Tax::factory()->create(['name' => 'VAT', 'code' => 'VAT-14']);
        Discount::factory()->create(['name' => 'Offer', 'code' => 'OFFER']);
        foreach (['ar', 'en'] as $locale) {
            $path = tempnam(sys_get_temp_dir(), 'employee-template-');
            app(ProductImportWorkbook::class)->template($this->actor, $path, true, $locale);
            app(ProductImportWorkbook::class)->inspect($path);
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/workbook.xml');
            $this->assertStringContainsString('ImportProducts', $xml);
            $this->assertStringContainsString(ProductImportEmployeeTemplate::productFormula(), html_entity_decode($xml));
            $this->assertStringContainsString('state="hidden"', $xml);
            $sheet = $zip->getFromName('xl/worksheets/sheet3.xml');
            $this->assertStringContainsString('<formula1>ImportProducts</formula1>', $sheet);
            $this->assertStringContainsString('sqref="A2:A10001"', $sheet);
            $this->assertStringContainsString('rightToLeft="'.($locale === 'ar' ? '1' : '0').'"', $sheet);
            $products = $zip->getFromName('xl/worksheets/sheet2.xml');
            $this->assertStringContainsString(__('product_import.employee_columns.name', [], $locale), $products);
            foreach (['product_key', 'variant_key', 'default_sku', $this->brand->uuid, $this->color->uuid] as $technical) {
                $this->assertStringNotContainsString($technical, $products.$sheet);
            }
            $zip->close();
            $reader = new Reader;
            $reader->open($path);
            $counts = [];
            foreach ($reader->getSheetIterator() as $tab) {
                $counts[$tab->getName()] = iterator_count($tab->getRowIterator());
            }
            $reader->close();
            $this->assertSame(4, $counts['Products']);
            $this->assertSame(7, $counts['Variants']);
            if (getenv('PRODUCT_IMPORT_EMPLOYEE_EXAMPLES') === '1') {
                $directory = storage_path('framework/testing/employee-import-examples');
                File::ensureDirectoryExists($directory);
                copy($path, $directory.'/products-'.$locale.'.xlsx');
                foreach (['sample-cover.png' => 1, 'sample-variant.png' => 2] as $name => $width) {
                    $sampleImage = $this->image($name, $width);
                    copy($sampleImage->getRealPath(), $directory.'/'.$name);
                }
            }
            unlink($path);
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_generated_examples_round_trip_through_preview_and_creation_in_both_languages(): void
    {
        Size::factory()->create(['name' => '43']);
        Color::factory()->create(['name' => 'White']);
        foreach (['ar', 'en'] as $index => $locale) {
            $path = tempnam(sys_get_temp_dir(), 'example-roundtrip-');
            app(ProductImportWorkbook::class)->template($this->actor, $path, true, $locale);
            $upload = UploadedFile::fake()->createWithContent('example-'.$locale.'.xlsx', file_get_contents($path));
            unlink($path);
            $import = app(ProductImportManager::class)->submit($this->actor, $upload, [$this->image('sample-cover.png'), $this->image('sample-variant.png', 2)]);
            app(ProductImportProcessor::class)->run($import->id);
            $this->assertSame(3, $import->entries()->where('status', 'valid')->count(), json_encode($import->entries()->pluck('errors')->all()));
            $this->finish($import->fresh());
            $this->assertSame('completed', $import->fresh()->status);
            $this->assertDatabaseCount('products', ($index + 1) * 3);
            $this->assertDatabaseCount('product_variants', ($index + 1) * 6);
            $this->assertDatabaseCount('product_images', ($index + 1) * 2);
        }
    }

    public function test_arabic_business_fields_resolve_references_images_assignments_and_generated_identifiers_atomically(): void
    {
        $tax = Tax::factory()->create(['name' => 'VAT', 'code' => 'VAT14']);
        $discount = Discount::factory()->create(['name' => 'Offer', 'code' => 'OFF10']);
        $data = $this->fixture('حذاء & حزام / "صيف"', [
            'ProductTaxes' => [['product_name' => 'حذاء & حزام / "صيف"', 'tax' => 'VAT — VAT14']],
            'ProductDiscounts' => [['product_name' => 'حذاء & حزام / "صيف"', 'discount' => 'Offer — OFF10']],
            'Images' => [['product_name' => 'حذاء & حزام / "صيف"', 'upload_name' => 'cover.png', 'is_primary' => 'نعم'],
                ['product_name' => 'حذاء & حزام / "صيف"', 'color' => 'Black', 'size' => '42', 'upload_name' => 'variant.png', 'is_primary' => 'نعم']],
        ]);
        $import = $this->prepare($data, [$this->image('cover.png'), $this->image('variant.png', 2)]);
        $this->assertSame('valid', $import->entries()->sole()->status, json_encode($import->entries()->sole()->errors));
        $payload = $import->entries()->sole()->payload;
        $this->assertSame($this->brand->uuid, $payload['Products'][0]['data']['brand_uuid']);
        $this->finish($import);
        $product = Product::sole();
        $this->assertMatchesRegularExpression('/^PRD-[A-F0-9]{32}$/D', $product->default_sku);
        $this->assertSame('حذاء & حزام / "صيف"', $product->name);
        $this->assertSame($this->color->id, $product->variants()->sole()->color_id);
        $this->assertNotEmpty($product->variants()->sole()->sku);
        $this->assertMatchesRegularExpression('/^[0-9]{13}$/D', $product->variants()->sole()->barcode);
        $this->assertSame(1, $product->images()->whereNull('product_variant_id')->count());
        $this->assertSame(1, $product->images()->whereNotNull('product_variant_id')->count());
        $this->assertSame([$tax->id], $product->taxes()->pluck('taxes.id')->all());
        $this->assertSame([$discount->id], $product->discounts()->pluck('discounts.id')->all());
        foreach (['stock_balances', 'stock_movements', 'fifo_layers', 'inventory_cost_states', 'treasury_operations', 'sales_invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('brands', 1);
        $this->assertDatabaseCount('colors', 1);
    }

    public function test_english_headers_values_numeric_sizes_and_fixed_selling_prices_are_mapped_server_side(): void
    {
        $import = $this->prepare(['Products' => [['name' => 'Air Max', 'status' => 'Active', 'pricing_mode' => 'Fixed Selling Price', 'shared_sale_price' => 1500]],
            'Variants' => [['product_name' => 'Air Max', 'size' => 42, 'variant_status' => 'Active']]], locale: 'en');
        $this->assertSame('valid', $import->entries()->sole()->status, json_encode($import->entries()->sole()->errors));
        $this->finish($import);
        $this->assertSame('1500.0000', Product::sole()->sale_price);
        $this->assertTrue(Product::sole()->is_active);
    }

    public function test_empty_employee_workbooks_explain_missing_product_rows_without_requesting_manual_keys(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $import = $this->prepare([], locale: $locale);
            $this->assertSame('failed', $import->status);
            $this->assertSame(['workbook' => [__('product_import.empty_products')]], $import->errors);
            $this->assertStringNotContainsString('يجب إدخال مفتاح فريد', json_encode($import->errors, JSON_UNESCAPED_UNICODE));
            $this->assertSame(0, $import->entries()->count());
        }
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('duplicateNames')]
    public function test_duplicate_normalized_names_are_rejected_with_physical_row_errors(string $first, string $second): void
    {
        $import = $this->prepare(['Products' => [['name' => $first], ['name' => $second]]]);
        $this->assertSame('failed', $import->status);
        $this->assertSame([
            'Products:2:name' => [__('product_import.employee_duplicate_name', ['name' => trim($first), 'row' => 2])],
            'Products:3:name' => [__('product_import.employee_duplicate_name', ['name' => trim($second), 'row' => 3])],
        ], $import->errors);
        $this->assertDatabaseCount('products', 0);
    }

    public static function duplicateNames(): array
    {
        return [['  Nike   Air ', 'nike air'], ['حذاء  صيفي', 'حذاء صيفي'], ['Ｓｈｏｅ', 'Shoe']];
    }

    #[DataProvider('invalidInputs')]
    public function test_missing_names_stale_product_links_and_invalid_pasted_dropdowns_never_create_records(array $data, string $state): void
    {
        $import = $this->prepare($data);
        $this->assertSame($state, $import->status);
        if ($state === 'ready') {
            $this->assertSame('invalid', $import->entries()->sole()->status);
        }
        $this->assertDatabaseCount('products', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing product name' => [['Products' => [['brand' => 'Nike']]], 'failed'],
            'renamed product' => [['Products' => [['name' => 'Renamed']], 'Variants' => [['product_name' => 'Old', 'sale_price' => '10']]], 'failed'],
            'missing child name' => [['Products' => [['name' => 'Shoe']], 'Variants' => [['sale_price' => '10']]], 'failed'],
            'pasted status' => [['Products' => [['name' => 'Shoe', 'status' => 'approved']]], 'ready'],
            'pasted pricing' => [['Products' => [['name' => 'Shoe', 'pricing_mode' => '0']]], 'ready'],
            'unknown brand' => [['Products' => [['name' => 'Shoe', 'brand' => 'Missing']]], 'ready'],
            'unknown size' => [['Products' => [['name' => 'Shoe']], 'Variants' => [['product_name' => 'Shoe', 'size' => '46', 'sale_price' => '10']]], 'ready'],
        ];
    }

    public function test_category_paths_disambiguate_names_and_bare_ambiguous_names_are_not_guessed(): void
    {
        $men = Category::factory()->create(['name' => 'Men']);
        $women = Category::factory()->create(['name' => 'Women']);
        $left = Category::factory()->create(['name' => 'Sneakers', 'parent_id' => $men->id]);
        Category::factory()->create(['name' => 'Sneakers', 'parent_id' => $women->id]);
        $options = app(ProductImportReferenceResolver::class)->options($this->actor, 'categories');
        $this->assertSame('Men / Sneakers', $options[$left->uuid]);
        $import = $this->prepare(['Products' => [['name' => 'Shoe', 'category' => 'Sneakers']]]);
        $this->assertSame('invalid', $import->entries()->sole()->status);
        app(ProductImportManager::class)->discard($this->actor, $import);
        $valid = $this->prepare(['Products' => [['name' => 'Shoe', 'category' => 'Men / Sneakers']]]);
        $this->finish($valid);
        $this->assertSame($left->id, Product::sole()->category_id);
    }

    public function test_inactive_and_unauthorized_references_are_not_dropdown_options_or_valid_selections(): void
    {
        $this->color->update(['is_active' => false]);
        $import = $this->prepare($this->fixture());
        $this->assertSame('invalid', $import->entries()->sole()->status);
        $this->assertNotContains('Black', app(ProductImportReferenceResolver::class)->options($this->actor, 'colors'));
        $outsider = User::factory()->create();
        $this->assertSame([], app(ProductImportReferenceResolver::class)->options($outsider, 'brands'));
        $this->assertDatabaseCount('products', 0);
    }

    public function test_duplicate_variant_combinations_are_rejected_without_manual_variant_keys(): void
    {
        $data = $this->fixture();
        $data['Variants'][] = $data['Variants'][0];
        $import = $this->prepare($data);
        $this->assertSame('invalid', $import->entries()->sole()->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_a_simple_variant_image_requires_an_explicit_variant_target_and_preserves_general_scope(): void
    {
        $import = $this->prepare(['Products' => [['name' => 'Simple']], 'Variants' => [['product_name' => 'Simple', 'sale_price' => '10']],
            'Images' => [['product_name' => 'Simple', 'upload_name' => 'cover.png'], ['product_name' => 'Simple', 'upload_name' => 'variant.png', 'image_scope' => 'صورة للمتغير']]], [$this->image('cover.png'), $this->image('variant.png', 2)]);
        $this->finish($import);
        $this->assertSame(1, Product::sole()->images()->whereNull('product_variant_id')->count());
        $this->assertSame(1, Product::sole()->images()->whereNotNull('product_variant_id')->count());
    }

    public function test_missing_variant_image_targets_are_rejected_without_guessing_another_variant(): void
    {
        Color::factory()->create(['name' => 'White']);
        $import = $this->prepare($this->fixture(replace: ['Images' => [['product_name' => 'حذاء فاخر', 'color' => 'White', 'size' => '42', 'upload_name' => 'cover.png']]]), [$this->image('cover.png')]);
        $this->assertSame('invalid', $import->entries()->sole()->status);
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_replacing_a_reference_after_preview_never_silently_rebinds_the_accepted_payload(): void
    {
        $import = $this->prepare($this->fixture());
        $uuid = $import->entries()->sole()->payload['Variants'][0]['data']['color_uuid'];
        $this->color->update(['name' => 'Renamed black']);
        Color::factory()->create(['name' => 'Black']);
        $this->finish($import);
        $this->assertSame('failed', $import->entries()->sole()->status);
        $this->assertSame($uuid, $import->entries()->sole()->payload['Variants'][0]['data']['color_uuid']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_identical_submissions_replay_once_and_reparsing_preserves_product_identity(): void
    {
        $file = $this->workbook($this->fixture());
        $manager = app(ProductImportManager::class);
        $import = $manager->submit($this->actor, $file, []);
        $this->assertSame($import->id, $manager->submit($this->actor, $file, [])->id);
        app(ProductImportProcessor::class)->run($import->id);
        $key = $import->entries()->sole()->product_key;
        $import->update(['status' => 'failed']);
        $manager->retry($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame($key, $import->entries()->sole()->product_key);
        $this->assertSame(strtolower(app(ProductImportProductIdentifiers::class)->productKey($import, 'حذاء فاخر')), $key);
        $this->finish($import);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertDatabaseCount('products', 1);
    }

    private function worker(ProductImport $import, string $mode = 'normal', string $start = '0'): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/ProductImportWorker.php')], base_path(), [
            'PI_MODE' => $mode, 'PI_IMPORT' => (string) $import->id, 'PI_ACTOR' => (string) $this->actor->id, 'PI_ROOT' => config('product-import.root'), 'PI_PUBLIC' => Storage::disk('public')->path(''), 'PI_START' => $start,
        ]);
        $worker->setTimeout(45)->start();

        return $worker;
    }

    public function test_reference_replacement_after_template_download_is_rejected_before_preview(): void
    {
        $file = $this->workbook($this->fixture());
        $this->color->update(['name' => 'Old black']);
        Color::factory()->create(['name' => 'Black']);
        $import = app(ProductImportManager::class)->submit($this->actor, $file, []);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('invalid', $import->entries()->sole()->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_reference_deactivation_and_account_permission_revocation_stop_accepted_work(): void
    {
        $import = $this->prepare($this->fixture());
        $this->size->update(['is_active' => false]);
        $this->finish($import);
        $this->assertSame('failed', $import->entries()->sole()->status);
        $this->assertDatabaseCount('products', 0);
        $this->size->update(['is_active' => true]);
        $another = $this->prepare($this->fixture('Another shoe'));
        app(ProductImportManager::class)->start($this->actor, $another);
        $this->actor->syncRoles([]);
        app(ProductImportProcessor::class)->run($another->id);
        $this->assertSame('paused', $another->fresh()->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_arbitrary_defined_formulas_are_rejected_instead_of_executed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'employee-formula-');
        app(ProductImportWorkbook::class)->template($this->actor, $path);
        $zip = new ZipArchive;
        $zip->open($path);
        $document = new \DOMDocument;
        $document->loadXML($zip->getFromName('xl/workbook.xml'), LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $xpath->query('//*[local-name()="definedName" and @name="ImportProducts"]')->item(0)->nodeValue = 'WEBSERVICE("https://example.invalid/private")';
        $zip->addFromString('xl/workbook.xml', $document->saveXML());
        $zip->close();
        try {
            $this->expectException(ValidationException::class);
            app(ProductImportWorkbook::class)->inspect($path);
        } finally {
            unlink($path);
        }
    }

    public function test_legacy_informational_references_over_the_employee_limit_still_import_and_release_source_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-references-');
        app(ProductImportWorkbook::class)->legacyTemplate($this->actor, $path, true);
        $zip = new ZipArchive;
        $zip->open($path);
        $document = new \DOMDocument;
        $document->loadXML($zip->getFromName('xl/worksheets/sheet7.xml'), LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $data = $xpath->query('//*[local-name()="sheetData"]')->item(0);
        while ($data->firstChild !== null) {
            $data->removeChild($data->firstChild);
        }
        for ($number = 1; $number <= 5003; $number++) {
            $row = $document->createElementNS($document->documentElement->namespaceURI, 'row');
            $row->setAttribute('r', (string) $number);
            $cell = $document->createElementNS($document->documentElement->namespaceURI, 'c');
            $cell->setAttribute('r', 'A'.$number);
            $cell->setAttribute('t', 'inlineStr');
            $inline = $document->createElementNS($document->documentElement->namespaceURI, 'is');
            $inline->appendChild($document->createElementNS($document->documentElement->namespaceURI, 't', 'Information'));
            $cell->appendChild($inline);
            $row->appendChild($cell);
            $data->appendChild($row);
        }
        $zip->addFromString('xl/worksheets/sheet7.xml', $document->saveXML());
        $zip->close();
        $upload = UploadedFile::fake()->createWithContent('legacy.xlsx', file_get_contents($path));
        unlink($path);
        $import = app(ProductImportManager::class)->submit($this->actor, $upload, [$this->image('example-general.png'), $this->image('example-variant.png', 2)]);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('valid', $import->entries()->sole()->status);
        $this->finish($import->fresh());
        $this->assertDatabaseCount('products', 1);
        $import->refresh()->update(['expires_at' => now()->subDay()]);
        app(ProductImportRecovery::class)->recover();
        $this->assertNotNull($import->fresh()->purged_at);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame([], $import->entries()->sole()->payload);
    }

    public function test_internal_identity_does_not_depend_on_worksheet_row_numbers_or_existing_product_names(): void
    {
        $import = ProductImport::factory()->create(['actor_id' => $this->actor->id]);
        $format = app(ProductImportEmployeeFormat::class);
        $first = $format->row($import, 'Products', ['حذاء صيفي'], 'ar', 2);
        $moved = $format->row($import, 'Products', ['حذاء صيفي'], 'ar', 500);
        $this->assertSame($first['product_key'], $moved['product_key']);
        $other = ProductImport::factory()->create(['actor_id' => $this->actor->id]);
        $this->assertNotSame($first['product_key'], $format->row($other, 'Products', ['حذاء صيفي'], 'ar', 2)['product_key']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_concurrent_import_operations_with_the_same_business_name_do_not_merge_or_duplicate(): void
    {
        $first = $this->prepare($this->fixture('Same shoe'));
        $actor = app(SuperAdminProvisioner::class)->provision('another-importer@example.test', false, 'Concurrent Importer', 'a-secure-test-password');
        $data = $this->fixture('Same shoe');
        $data['Products'][0]['description'] = 'Another operation';
        $second = app(ProductImportManager::class)->submit($actor, $this->workbook($data), []);
        app(ProductImportProcessor::class)->run($second->id);
        app(ProductImportManager::class)->start($this->actor, $first);
        app(ProductImportManager::class)->start($actor, $second);
        $start = (string) (microtime(true) + 1);
        $workers = [$this->worker($first, start: $start), $this->worker($second, start: $start)];
        foreach ($workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
        }
        app(ProductImportProcessor::class)->run($first->id);
        app(ProductImportProcessor::class)->run($second->id);
        $this->assertDatabaseCount('products', 2);
        $this->assertSame(2, Product::distinct()->count('default_sku'));
        $this->assertSame(2, Product::where('name', 'Same shoe')->count());
    }

    #[DataProvider('crashModes')]
    public function test_employee_identities_and_images_survive_actual_worker_termination(string $mode, string $signal): void
    {
        $import = $this->prepare($this->fixture(replace: ['Images' => [['product_name' => 'حذاء فاخر', 'upload_name' => 'cover.png']]]), [$this->image('cover.png')]);
        $key = $import->entries()->sole()->product_key;
        app(ProductImportManager::class)->start($this->actor, $import);
        $worker = $this->worker($import, $mode);
        $this->assertTrue($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, $signal)));
        $worker->stop(0);
        $this->assertNotSame(0, $worker->getExitCode());
        DB::table('product_import_slots')->update(['lease_until' => now()->subSecond()]);
        $import->update(['lease_until' => now()->subSecond()]);
        app(ProductImportRecovery::class)->recover();
        $replay = $this->worker($import);
        $this->assertSame(0, $replay->wait(), $replay->getErrorOutput());
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame($key, $import->entries()->sole()->product_key);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_images', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
        $this->assertSame([], glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
    }

    public static function crashModes(): array
    {
        return [['before_commit', "allocated\n"], ['after_commit', "committed\n"]];
    }
}
