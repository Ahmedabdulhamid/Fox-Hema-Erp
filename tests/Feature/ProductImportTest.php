<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Products\Filament\Resources\Products\Pages\ImportProducts;
use App\Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductImportEntry;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Products\Services\ProductImportAccess;
use App\Modules\Products\Services\ProductImportManager;
use App\Modules\Products\Services\ProductImportProcessor;
use App\Modules\Products\Services\ProductImportStorage;
use App\Modules\Products\Services\ProductImportWorkbook;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Taxes\Models\Tax;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use UsesIsolatedDatabase;

    private const TRACKING_REPAIR = 'app/Modules/Products/Database/Migrations/2026_11_20_054539_repair_product_import_tracking_columns.php';

    protected User $actor;

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
        Storage::set('public', Storage::fake('product-import-public-'.Str::uuid()));
        config(['product-import.root' => storage_path('framework/testing/product-import-'.Str::uuid()), 'product-import.free_disk_bytes' => 0]);
    }

    protected function image(string $name = 'front.png', bool $other = false): UploadedFile
    {
        $image = imagecreatetruecolor($other ? 2 : 1, 1);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function workbook(array $sheets = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Instructions');
        $this->writeRow($writer, [ProductImportWorkbook::VERSION]);
        foreach (ProductImportWorkbook::HEADERS as $sheet => $headers) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
            $this->writeRow($writer, $headers);
            foreach ($sheets[$sheet] ?? [] as $values) {
                $this->writeRow($writer, array_map(fn (string $header): mixed => $values[$header] ?? '', $headers));
            }
        }
        $writer->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('products.xlsx', $bytes);
    }

    private function writeRow(Writer $writer, array $values): void
    {
        $writer->addRow(new Row(array_map(fn (mixed $value): StringCell => new StringCell((string) $value, null), $values)));
    }

    protected function submit(array $sheets, array $images = []): ProductImport
    {
        return app(ProductImportManager::class)->submit($this->actor, $this->workbook($sheets), $images);
    }

    protected function prepareImport(ProductImport $import): ProductImport
    {
        app(ProductImportProcessor::class)->run($import->id);

        return $import->fresh();
    }

    protected function finish(ProductImport $import): ProductImport
    {
        app(ProductImportManager::class)->start($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);

        return $import->fresh();
    }

    public function test_template_empty_example_and_filament_workflow_are_authorized_and_parseable(): void
    {
        $this->assertTrue(app(ProductsAccess::class)->allows($this->actor, 'products.import'));
        foreach ([false, true] as $example) {
            $path = tempnam(sys_get_temp_dir(), 'template-test-');
            app(ProductImportWorkbook::class)->template($this->actor, $path, $example);
            app(ProductImportWorkbook::class)->inspect($path);
            $this->assertGreaterThan(0, filesize($path));
            unlink($path);
        }
        $this->get(route('products.import.download', ['kind' => 'template']))->assertOk()->assertDownload('products-template.xlsx');
        Livewire::test(ImportProducts::class)->assertSee(__('product_import.instructions'), escape: false);
        Livewire::test(ListProducts::class)->assertActionVisible('importExcel');
    }

    private function legacyTrackingSchema(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_treasury_', DB::connection()->getDatabaseName());
        $this->assertSame(DB::connection()->getDatabaseName(), DB::selectOne('SELECT DATABASE() AS db')->db);
        Schema::table('product_import_entries', fn (Blueprint $table) => $table->dropColumn(['source_rows', 'variant_count', 'image_count']));
        Schema::table('product_imports', fn (Blueprint $table) => $table->dropColumn('recovery_attempts'));
        DB::table('migrations')->where('migration', pathinfo(self::TRACKING_REPAIR, PATHINFO_FILENAME))->delete();
    }

    public function test_tracking_repair_reproduces_missing_column_and_restores_livewire_upload_preview_and_counts(): void
    {
        $sizes = Size::factory()->count(2)->create();
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $upload = $this->workbook([
            'Products' => [['product_key' => 'REPAIR-A', 'name' => 'Repair A'], ['product_key' => 'REPAIR-B', 'name' => 'Repair B']],
            'Variants' => [
                ['product_key' => 'REPAIR-A', 'variant_key' => 'one', 'size_uuid' => $sizes[0]->uuid, 'sale_price' => '10'],
                ['product_key' => 'REPAIR-A', 'variant_key' => 'two', 'size_uuid' => $sizes[1]->uuid, 'sale_price' => '20'],
                ['product_key' => 'REPAIR-B', 'variant_key' => 'simple', 'sale_price' => '30'],
            ],
            'ProductTaxes' => [['product_key' => 'REPAIR-A', 'tax_uuid' => $tax->uuid]],
            'ProductDiscounts' => [['product_key' => 'REPAIR-A', 'discount_uuid' => $discount->uuid]],
            'Images' => [['product_key' => 'REPAIR-A', 'image_key' => 'cover', 'upload_name' => 'front.png']],
        ]);
        $images = [$this->image()];
        $import = app(ProductImportManager::class)->submit($this->actor, $upload, $images);
        $this->prepareImport($import);
        $this->assertSame(2, $import->entries()->where('status', 'valid')->count());
        $before = $import->entries()->get()->mapWithKeys(fn (ProductImportEntry $entry): array => [$entry->id => $entry->only(['payload', 'payload_hash', 'status', 'attempts', 'updated_at'])]);
        $this->legacyTrackingSchema();
        try {
            DB::table('product_import_entries')->where('import_id', $import->id)
                ->selectRaw('status, COUNT(*) AS total, SUM(source_rows) AS source_rows, SUM(variant_count) AS variants, SUM(image_count) AS images')->groupBy('status')->get();
            $this->fail('The legacy schema must reproduce the reported aggregate failure.');
        } catch (QueryException $exception) {
            $this->assertSame('42S22', $exception->errorInfo[0]);
            $this->assertSame(1054, $exception->errorInfo[1]);
            $this->assertStringContainsString('source_rows', $exception->getMessage());
        }
        $this->artisan('migrate', ['--path' => self::TRACKING_REPAIR, '--no-interaction' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('product_imports', 'recovery_attempts'));
        foreach ($import->entries()->get() as $entry) {
            $this->assertEquals($before[$entry->id], $entry->only(['payload', 'payload_hash', 'status', 'attempts', 'updated_at']));
        }
        $page = Livewire::test(ImportProducts::class)->set('workbook', $upload)->set('images', $images)
            ->call('prepare')->assertHasNoErrors()->assertSet('importId', $import->id)->assertSee('Repair A')->assertSee('Repair B');
        $this->assertSame(8, (int) $import->entries()->sum('source_rows'));
        $this->assertSame(3, (int) $import->entries()->sum('variant_count'));
        $this->assertSame(1, (int) $import->entries()->sum('image_count'));
        $this->assertDatabaseCount('products', 0);
        $page->call('startImport')->assertHasNoErrors();
        app(ProductImportProcessor::class)->run($import->id);
        $page->call('$refresh')->assertSee(__('product_import.status_completed'))
            ->assertSee(__('product_import.row_counts', ['succeeded' => 8, 'failed' => 0, 'variants' => 3, 'images' => 1]));
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_variants', 3);
        $this->assertDatabaseCount('product_images', 1);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertDatabaseCount('products', 2);
    }

    public function test_tracking_repair_uses_retained_rows_then_compact_results_without_overwriting_existing_metadata(): void
    {
        $import = $this->prepareImport($this->submit(['Products' => [['product_key' => 'RETAINED', 'name' => 'Retained']],
            'Variants' => [['product_key' => 'RETAINED', 'variant_key' => 'one', 'sale_price' => '10']],
            'Images' => [['product_key' => 'RETAINED', 'image_key' => 'front', 'upload_name' => 'front.png']]], [$this->image()]));
        $entry = $import->entries()->sole();
        $entry->update(['payload' => []]);
        $this->legacyTrackingSchema();
        $repair = require base_path(self::TRACKING_REPAIR);
        $repair->up();
        $this->assertSame(3, $entry->fresh()->source_rows);
        $this->assertSame(1, $entry->fresh()->variant_count);
        $this->assertSame(1, $entry->fresh()->image_count);
        $entry->update(['source_rows' => 9, 'result' => ['variants' => ['one' => ['id' => 123]], 'images' => ['front' => 456]]]);
        $import->update(['recovery_attempts' => 2]);
        $import->rows()->delete();
        Schema::table('product_import_entries', fn (Blueprint $table) => $table->dropColumn(['variant_count', 'image_count']));
        $repair->up();
        $this->assertSame(9, $entry->fresh()->source_rows);
        $this->assertSame(1, $entry->fresh()->variant_count);
        $this->assertSame(1, $entry->fresh()->image_count);
        $this->assertSame(2, $import->fresh()->recovery_attempts);
        $snapshot = (array) DB::table('product_import_entries')->where('id', $entry->id)->first();
        $repair->up();
        $this->assertSame($snapshot, (array) DB::table('product_import_entries')->where('id', $entry->id)->first());
        try {
            $repair->down();
            $this->fail('Tracking repair must refuse destructive rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }
        $this->assertSame($snapshot, (array) DB::table('product_import_entries')->where('id', $entry->id)->first());
    }

    public function test_livewire_upload_preview_start_progress_and_error_download_work_together(): void
    {
        $page = Livewire::test(ImportProducts::class)
            ->assertSeeHtml('data-import-step="1"')
            ->set('workbook', $this->workbook(['Products' => [['product_key' => 'UI-001', 'name' => 'UI product']],
                'Images' => [['product_key' => 'UI-001', 'image_key' => 'front', 'upload_name' => 'front.png']]]))
            ->set('images', [$this->image()])->call('prepare')->assertHasNoErrors();
        $import = ProductImport::findOrFail($page->get('importId'));
        $page->assertSeeHtml('data-import-step="2"');
        $this->prepareImport($import);
        $page->call('$refresh')->assertSee('UI product')->call('startImport')->assertHasNoErrors();
        app(ProductImportProcessor::class)->run($import->id);
        $page->call('$refresh')->assertSee(__('product_import.status_completed'))->assertSeeHtml('data-import-step="3"');
        $this->assertSame(2, $import->entries()->sole()->source_rows);
        $this->get(route('products.import.download', ['kind' => 'errors', 'uuid' => $import->uuid]))->assertOk()->assertDownload('product-import-errors.csv');
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_upload_controls_preserve_filenames_and_support_removal_in_both_locales(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $page = Livewire::test(ImportProducts::class)
                ->assertSee(__('product_import.page_heading'))
                ->assertSeeHtml('dir="'.$direction.'"')
                ->set('workbook', $this->workbook())
                ->set('images', [$this->image('front.png'), $this->image('variant.png', true)])
                ->assertSee('products.xlsx')
                ->assertSee('front.png')
                ->assertSee('variant.png');
            $this->assertCount(2, $page->get('images'));
            $this->assertStringContainsString('/preview-file/', $page->html());
            if (getenv('PRODUCT_IMPORT_UI_SNAPSHOTS') === '1') {
                $directory = storage_path('framework/testing/product-import-ui');
                File::ensureDirectoryExists($directory);
                $html = $page->html();
                foreach ($page->get('images') as $image) {
                    $html = str_replace(e($image->temporaryUrl()), 'data:'.$image->getMimeType().';base64,'.base64_encode(file_get_contents($image->getRealPath())), $html);
                }
                file_put_contents($directory.'/'.$locale.'.html', $html);
            }
            $page->call('removeImage', 0)->assertHasNoErrors();
            $this->assertCount(1, $page->get('images'));
            $this->assertSame('variant.png', $page->get('images')[0]->getClientOriginalName());
            $page->call('removeWorkbook')->assertSet('workbook', null)
                ->call('newUpload')->assertSet('images', [])->assertSet('importId', null)->assertSeeHtml('data-import-step="1"');
        }
    }

    public function test_discard_releases_admission_and_retry_cannot_bypass_another_active_upload(): void
    {
        $first = $this->submit(['Products' => [['product_key' => 'CANCEL', 'name' => 'Cancel']]]);
        $this->prepareImport($first);
        app(ProductImportManager::class)->discard($this->actor, $first);
        $this->assertSame('cancelled', $first->fresh()->status);
        $other = $this->submit(['Products' => [['product_key' => 'OTHER', 'name' => 'Other']]]);
        try {
            app(ProductImportManager::class)->retry($this->actor, $first);
            $this->fail('A retry bypassed per-actor admission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('workbook', $exception->errors());
        }
        app(ProductImportManager::class)->discard($this->actor, $other);
        app(ProductImportManager::class)->retry($this->actor, $first);
        $this->assertSame('uploaded', $first->fresh()->status);
        $this->assertDatabaseCount('products', 0);
    }

    #[DataProvider('replacementValidationFailures')]
    public function test_replace_excel_allows_corrected_upload_after_validation_failure(string $locale, bool $structural): void
    {
        app()->setLocale($locale);
        $page = Livewire::test(ImportProducts::class)->set('workbook', $this->workbook([
            'Products' => [['product_key' => 'REPLACE', 'name' => 'Original invalid product']],
            'Variants' => [['product_key' => $structural ? 'MISSING' : 'REPLACE', 'variant_key' => 'base', 'sale_price' => 'invalid']],
        ]))->call('prepare')->assertHasNoErrors();
        $old = ProductImport::findOrFail($page->get('importId'));
        $this->prepareImport($old);
        $this->assertSame($structural ? 'failed' : 'ready', $old->fresh()->status);
        $errors = $structural ? $old->fresh()->errors : $old->entries()->sole()->errors;
        $this->assertNotEmpty($errors);
        $page->call('$refresh')->assertSee(__('product_import.replace_excel'));
        $page->call('newUpload')->assertHasNoErrors()->assertSet('importId', null)->assertSeeHtml('data-import-step="1"');
        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame($errors, $structural ? $old->fresh()->errors : $old->entries()->sole()->errors);
        app(ProductImportProcessor::class)->run($old->id);
        $this->assertSame('cancelled', $old->fresh()->status);
        $page->set('workbook', $this->workbook([
            'Products' => [['product_key' => 'REPLACE', 'name' => 'Corrected product']],
            'Variants' => [['product_key' => 'REPLACE', 'variant_key' => 'base', 'sale_price' => '20']],
        ]))->call('prepare')->assertHasNoErrors();
        $new = ProductImport::findOrFail($page->get('importId'));
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('ready', $this->prepareImport($new)->status);
        $this->assertSame('valid', $new->entries()->sole()->status);
        $page->call('$refresh')->call('startImport')->assertHasNoErrors();
        app(ProductImportProcessor::class)->run($new->id);
        $this->assertSame('completed', $new->fresh()->status);
        $this->assertSame('Corrected product', Product::sole()->name);
        $this->assertDatabaseCount('product_imports', 2);
    }

    public static function replacementValidationFailures(): array
    {
        return [['ar', false], ['en', true]];
    }

    public function test_replace_excel_repeated_failures_release_admission_and_preserve_history(): void
    {
        $page = Livewire::test(ImportProducts::class);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $key = 'FAILED-'.$attempt;
            $page->set('workbook', $this->workbook(['Products' => [['product_key' => $key, 'name' => 'Failed '.$attempt]],
                'Variants' => [['product_key' => $key, 'variant_key' => 'base', 'sale_price' => '-1']]]))->call('prepare')->assertHasNoErrors();
            $import = ProductImport::findOrFail($page->get('importId'));
            $this->prepareImport($import);
            $this->assertSame('invalid', $import->entries()->sole()->status);
            $page->call('newUpload')->assertHasNoErrors()->assertSet('importId', null);
            $this->assertSame(0, ProductImport::where('actor_id', $this->actor->id)->whereIn('status', ProductImportManager::ACTIVE)->count());
        }
        $this->assertSame(3, ProductImport::where('status', 'cancelled')->count());
        $this->assertSame(3, ProductImportEntry::where('status', 'invalid')->count());
        $page->set('workbook', $this->workbook(['Products' => [['product_key' => 'CORRECT', 'name' => 'Correct']]]))->call('prepare')->assertHasNoErrors();
        $this->assertSame('ready', $this->prepareImport(ProductImport::findOrFail($page->get('importId')))->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_replace_excel_same_file_resubmission_reuses_identity_and_revalidates(): void
    {
        $file = $this->workbook(['Products' => [['product_key' => 'SAME', 'name' => 'Same invalid file']],
            'Variants' => [['product_key' => 'SAME', 'variant_key' => 'base', 'sale_price' => '-1']]]);
        $manager = app(ProductImportManager::class);
        $import = $manager->submit($this->actor, $file, []);
        $this->prepareImport($import);
        $this->assertSame($import->id, $manager->submit($this->actor, $file, [])->id);
        $fingerprint = $import->fingerprint;
        $key = $import->entries()->sole()->product_key;
        $page = Livewire::test(ImportProducts::class)->call('showImport', $import->id);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $page->call('newUpload')->assertHasNoErrors();
            $page->set('workbook', $file)->call('prepare')->assertHasNoErrors()->assertSet('importId', $import->id);
            $this->assertSame('uploaded', $import->fresh()->status);
            $this->prepareImport($import);
            $this->assertSame('invalid', $import->entries()->sole()->status);
            $this->assertSame($key, $import->entries()->sole()->product_key);
            $this->assertSame($fingerprint, $import->fresh()->fingerprint);
        }
        $this->assertDatabaseCount('product_imports', 1);
        $this->assertDatabaseCount('product_import_entries', 1);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_replace_excel_preserves_started_imports_and_cannot_discard_a_live_lease(): void
    {
        $import = $this->prepareImport($this->submit(['Products' => [['product_key' => 'STARTED', 'name' => 'Started']]]));
        $import->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        Livewire::test(ImportProducts::class)->call('showImport', $import->id)->call('newUpload')->assertStatus(409);
        $this->assertSame('ready', $import->fresh()->status);
        $import->update(['lease_token' => null, 'lease_until' => null]);
        app(ProductImportManager::class)->start($this->actor, $import);
        $snapshot = $import->fresh()->getRawOriginal();
        Livewire::test(ImportProducts::class)->call('showImport', $import->id)
            ->assertDontSee(__('product_import.replace_excel'))->call('newUpload')->assertHasNoErrors();
        $this->assertSame($snapshot, $import->fresh()->getRawOriginal());
        try {
            app(ProductImportManager::class)->discard($this->actor, $import);
            $this->fail('A confirmed import was discarded.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        try {
            $this->submit(['Products' => [['product_key' => 'OTHER-ACTIVE', 'name' => 'Other']]]);
            $this->fail('An active confirmed import was superseded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('workbook', $exception->errors());
        }
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 1);
    }

    #[DataProvider('replacementRaces')]
    public function test_replace_excel_concurrent_admission_and_confirmation_are_serialized(bool $raceStart): void
    {
        $import = $this->prepareImport($this->submit(['Products' => [['product_key' => 'RACE-VALID', 'name' => 'Valid'], ['product_key' => 'RACE-INVALID', 'name' => 'Invalid']],
            'Variants' => [['product_key' => 'RACE-INVALID', 'variant_key' => 'base', 'sale_price' => '-1']]]));
        foreach (['one', 'two'] as $filename) {
            $file = $this->workbook(['Products' => [['product_key' => 'NEW-'.$filename, 'name' => 'Replacement '.$filename]]]);
            copy($file->getRealPath(), config('product-import.root').'/replacement-'.$filename.'.xlsx');
        }
        $start = (string) (microtime(true) + 1);
        $workers = [];
        foreach (['replace', $raceStart ? 'start' : 'replace'] as $index => $mode) {
            $worker = new Process([PHP_BINARY, base_path('tests/Support/ProductImportWorker.php')], base_path(), [
                'PI_MODE' => $mode, 'PI_IMPORT' => (string) $import->id, 'PI_ACTOR' => (string) $this->actor->id,
                'PI_ROOT' => config('product-import.root'), 'PI_PUBLIC' => Storage::disk('public')->path(''), 'PI_START' => $start,
                'PI_REPLACEMENT' => $index === 0 ? 'one' : 'two',
            ]);
            $worker->setTimeout(45)->start();
            $workers[] = $worker;
        }
        $statuses = [];
        foreach ($workers as $worker) {
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            $statuses[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR)['status'];
        }
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $this->assertSame(1, ProductImport::where('actor_id', $this->actor->id)->whereIn('status', ProductImportManager::ACTIVE)->count());
        $this->assertSame($import->fresh()->accepted_at === null ? 'cancelled' : 'queued', $import->fresh()->status);
        $this->assertSame(1, $import->entries()->where('status', 'invalid')->count());
        $this->assertDatabaseCount('products', 0);
    }

    public static function replacementRaces(): array
    {
        return [[false], [true]];
    }

    public function test_arabic_multi_variant_fixed_prices_taxes_discounts_and_images_commit_atomically(): void
    {
        $small = Size::factory()->create();
        $large = Size::factory()->create();
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $import = $this->submit([
            'Products' => [['product_key' => 'SHIRT-001', 'name' => 'قميص قطني', 'has_fixed_selling_price' => '1', 'shared_sale_price' => '249.9000', 'status' => 'active']],
            'Variants' => [
                ['product_key' => 'SHIRT-001', 'variant_key' => 'small', 'size_uuid' => $small->uuid, 'reference_cost' => '120.0000'],
                ['product_key' => 'SHIRT-001', 'variant_key' => 'large', 'size_uuid' => $large->uuid],
            ],
            'ProductTaxes' => [['product_key' => 'SHIRT-001', 'tax_uuid' => $tax->uuid]],
            'ProductDiscounts' => [['product_key' => 'SHIRT-001', 'discount_uuid' => $discount->uuid]],
            'Images' => [
                ['product_key' => 'SHIRT-001', 'image_key' => 'front', 'upload_name' => 'front.png', 'is_primary' => '1', 'sort_order' => '4'],
                ['product_key' => 'SHIRT-001', 'variant_key' => 'large', 'image_key' => 'large-front', 'upload_name' => 'large.png', 'is_primary' => '1'],
            ],
        ], [$this->image(), $this->image('large.png', true)]);
        $prepared = $this->prepareImport($import);
        $this->assertSame('ready', $prepared->status, json_encode($prepared->errors));
        $this->assertSame('completed', $this->finish($import)->status);
        $product = Product::where('default_sku', 'SHIRT-001')->sole();
        $this->assertSame('قميص قطني', $product->name);
        $this->assertTrue($product->is_active);
        $this->assertSame('249.9000', $product->sale_price);
        $this->assertSame(2, $product->variants()->count());
        $this->assertSame('120.0000', $product->variants()->where('size_id', $small->id)->sole()->cost_price);
        $this->assertSame([$tax->id], $product->taxes()->pluck('taxes.id')->all());
        $this->assertSame([$discount->id], $product->discounts()->pluck('discounts.id')->all());
        $this->assertSame(4, $product->images()->whereNull('product_variant_id')->sole()->sort_order);
        $largeImage = $product->images()->whereNotNull('product_variant_id')->sole();
        $this->assertSame($product->variants()->where('size_id', $large->id)->sole()->id, $largeImage->product_variant_id);
        $this->assertSame(2, $product->images()->where('is_primary', true)->count());
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
        foreach (['stock_balances', 'stock_movements', 'fifo_layers', 'inventory_cost_states', 'purchase_receipts', 'treasury_operations', 'sales_invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_images', 2);
    }

    public function test_draft_without_variants_and_decimal_variant_price_are_supported(): void
    {
        $import = $this->submit(['Products' => [
            ['product_key' => '0001', 'name' => 'Draft'],
            ['product_key' => '0002', 'name' => 'Priced'],
        ], 'Variants' => [['product_key' => '0002', 'variant_key' => 'base', 'sale_price' => '99999999999999.9999']]]);
        $prepared = $this->prepareImport($import);
        $this->assertSame('ready', $prepared->status, json_encode($prepared->errors));
        $this->assertSame('completed', $this->finish($import)->status);
        $this->assertSame(0, Product::where('default_sku', '0001')->sole()->variants()->count());
        $this->assertSame('99999999999999.9999', Product::where('default_sku', '0002')->sole()->variants()->sole()->sale_price);
    }

    public function test_duplicate_submission_double_start_and_replay_do_not_create_duplicates(): void
    {
        $file = $this->workbook(['Products' => [['product_key' => 'REPLAY', 'name' => 'Replay']]]);
        $manager = app(ProductImportManager::class);
        $first = $manager->submit($this->actor, $file, []);
        $this->assertSame($first->id, $manager->submit($this->actor, $file, [])->id);
        $this->prepareImport($first);
        $manager->start($this->actor, $first);
        $manager->start($this->actor, $first);
        $processor = app(ProductImportProcessor::class);
        $processor->run($first->id);
        $processor->run($first->id);
        $this->assertDatabaseCount('products', 1);
        $other = $this->submit(['Products' => [['product_key' => 'replay', 'name' => 'Different bytes']]]);
        $this->assertSame('ready', $this->prepareImport($other)->status);
        $this->assertSame('invalid', $other->entries()->sole()->status);
        $this->assertStringContainsString(__('product_import.existing_product'), json_encode($other->entries()->sole()->errors, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Replay', Product::sole()->name);
    }

    public static function invalidWorkbooks(): array
    {
        return [
            'case duplicate parent' => [['Products' => [['product_key' => 'A', 'name' => 'One'], ['product_key' => 'a', 'name' => 'Two']]], 'failed'],
            'orphan variant' => [['Products' => [['product_key' => 'A', 'name' => 'One']], 'Variants' => [['product_key' => 'B', 'variant_key' => 'x', 'sale_price' => '1']]], 'failed'],
            'invalid decimal' => [['Products' => [['product_key' => 'A', 'name' => 'One']], 'Variants' => [['product_key' => 'A', 'variant_key' => 'x', 'sale_price' => '1.12345']]], 'ready'],
            'duplicate null pair' => [['Products' => [['product_key' => 'A', 'name' => 'One']], 'Variants' => [['product_key' => 'A', 'variant_key' => 'x', 'sale_price' => '1'], ['product_key' => 'A', 'variant_key' => 'y', 'sale_price' => '2']]], 'ready'],
            'missing image' => [['Products' => [['product_key' => 'A', 'name' => 'One']], 'Images' => [['product_key' => 'A', 'image_key' => 'front', 'upload_name' => 'missing.png']]], 'ready'],
            'invalid reference' => [['Products' => [['product_key' => 'A', 'name' => 'One', 'brand_uuid' => '11111111-1111-4111-8111-111111111111']]], 'ready'],
        ];
    }

    #[DataProvider('invalidWorkbooks')]
    public function test_invalid_workbooks_never_write_partial_products(array $sheets, string $state): void
    {
        $import = $this->submit($sheets);
        $this->assertSame($state, $this->prepareImport($import)->status);
        if ($state === 'ready') {
            $this->assertSame('invalid', $import->entries()->sole()->status);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_formulas_external_links_archives_and_row_limits_are_rejected(): void
    {
        $file = $this->workbook(['Products' => [['product_key' => 'FORMULA', 'name' => 'Product']]]);
        $path = tempnam(sys_get_temp_dir(), 'formula-import-');
        copy($file->getRealPath(), $path);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->addFromString('xl/worksheets/sheet2.xml', str_replace('</sheetData>', '<row r="3"><c r="A3"><f>1+1</f><v>2</v></c></row></sheetData>', $xml));
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('formulas.xlsx', file_get_contents($path));
        unlink($path);
        $import = app(ProductImportManager::class)->submit($this->actor, $file, []);
        $this->assertSame('failed', $this->prepareImport($import)->status);
        config(['product-import.expanded_bytes' => 50]);
        $next = $this->submit(['Products' => [['product_key' => 'LIMIT', 'name' => 'Limit']]]);
        $this->assertSame('failed', $this->prepareImport($next)->status);
    }

    protected function scopedActor(array $permissions): User
    {
        $location = Location::create(['name' => 'Import branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $location->id);
        $actor->givePermissionTo($permissions);

        return $actor->fresh();
    }

    public function test_permission_button_direct_url_forged_requests_and_history_are_protected(): void
    {
        $import = $this->submit(['Products' => [['product_key' => 'PRIVATE', 'name' => 'Private']]]);
        $actor = $this->scopedActor(['products.view', 'products.create']);
        $this->assertFalse(app(ProductImportAccess::class)->allows($actor));
        Livewire::test(ListProducts::class)->assertActionHidden('importExcel');
        $this->get(ProductResource::getUrl('import'))->assertForbidden();
        $this->get(route('products.import.download', ['kind' => 'errors', 'uuid' => $import->uuid]))->assertForbidden();
        app(LocationContext::class)->setFor($actor, (int) $actor->locations()->value('locations.id'));
        $actor->givePermissionTo('products.import');
        $actor = $actor->fresh();
        $this->actingAs($actor);
        RequestAuthorizationFacts::forget();
        app(LocationContext::class)->setFor($actor, (int) $actor->locations()->value('locations.id'));
        $this->assertTrue(app(ProductImportAccess::class)->allows($actor));
        $this->get(route('products.import.download', ['kind' => 'source', 'uuid' => $import->uuid]))->assertForbidden();
    }

    public function test_authorized_importer_cannot_forge_another_owners_history_selection(): void
    {
        $import = $this->submit(['Products' => [['product_key' => 'OTHER', 'name' => 'Other owner']]]);
        $actor = $this->scopedActor(['products.view', 'products.create', 'products.import']);
        $this->assertTrue(app(ProductImportAccess::class)->allows($actor));
        Livewire::test(ImportProducts::class)->assertOk()->call('showImport', $import->id)->assertForbidden();
    }

    public function test_revocation_between_chunks_preserves_committed_product_and_stops_remaining_work(): void
    {
        $this->actor = $this->scopedActor(['products.view', 'products.create', 'products.import']);
        $import = $this->submit(['Products' => [['product_key' => 'FIRST', 'name' => 'First'], ['product_key' => 'SECOND', 'name' => 'Second']]]);
        $this->prepareImport($import);
        config(['product-import.chunk_products' => 1]);
        $this->finish($import);
        $this->assertDatabaseCount('products', 1);
        $this->actor->locations()->detach();
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('paused', $import->fresh()->status);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('pending', $import->entries()->where('product_key', 'second')->sole()->status);
    }

    public function test_identical_images_within_a_product_and_reused_across_variants_are_imported(): void
    {
        $sizes = Size::factory()->count(2)->create();
        $front = $this->image();
        $back = $this->image('back.png');
        $this->assertSame(hash_file('sha256', $front->getRealPath()), hash_file('sha256', $back->getRealPath()));
        $workbook = $this->workbook([
            'Products' => [['product_key' => 'IDENTICAL', 'name' => 'Identical images']],
            'Variants' => [
                ['product_key' => 'IDENTICAL', 'variant_key' => 'small', 'size_uuid' => $sizes[0]->uuid, 'sale_price' => '10'],
                ['product_key' => 'IDENTICAL', 'variant_key' => 'large', 'size_uuid' => $sizes[1]->uuid, 'sale_price' => '10'],
            ],
            'Images' => [
                ['product_key' => 'IDENTICAL', 'image_key' => 'front', 'upload_name' => 'front.png'],
                ['product_key' => 'IDENTICAL', 'image_key' => 'back', 'upload_name' => 'back.png'],
                ['product_key' => 'IDENTICAL', 'variant_key' => 'small', 'image_key' => 'small-front', 'upload_name' => 'front.png', 'is_primary' => '1'],
                ['product_key' => 'IDENTICAL', 'variant_key' => 'large', 'image_key' => 'large-front', 'upload_name' => 'front.png', 'is_primary' => '1'],
            ],
        ]);
        $import = app(ProductImportManager::class)->submit($this->actor, $workbook, [$front, $back]);
        $this->prepareImport($import);
        $this->assertSame('valid', $import->entries()->sole()->status, json_encode($import->entries()->sole()->errors));
        $oldError = 'تكرار محتوى الصورة داخل المنتج غير مدعوم.';
        $import->entries()->sole()->update(['status' => 'invalid', 'errors' => ['Images:3:upload_name' => [$oldError]]]);
        $page = Livewire::test(ImportProducts::class)->call('showImport', $import->id)->assertSee($oldError);
        $page->call('newUpload')->assertHasNoErrors()
            ->set('workbook', $workbook)->set('images', [$front, $back])
            ->call('prepare')->assertHasNoErrors()->assertSet('importId', $import->id);
        $this->prepareImport($import);
        $this->assertSame('valid', $import->entries()->sole()->status);
        $this->assertEmpty($import->entries()->sole()->errors);
        $page->call('$refresh')->assertDontSee($oldError);
        foreach (['ar', 'en'] as $locale) {
            $message = __('product_import.duplicate_image', [], $locale);
            $this->assertStringNotContainsString($oldError, $message);
            $this->assertStringNotContainsString('duplicate content', $message);
        }
        $this->assertSame('completed', $this->finish($import)->status);
        $product = Product::where('default_sku', 'IDENTICAL')->sole();
        $this->assertSame(2, $import->assets()->count());
        $this->assertSame(4, $product->images()->count());
        $this->assertSame(2, $product->images()->whereNull('product_variant_id')->count());
        foreach ($product->variants as $variant) {
            $this->assertTrue($product->images()->where('product_variant_id', $variant->id)->sole()->is_primary);
        }
        $this->assertSame(4, $product->images->pluck('path')->unique()->count());
        foreach ($product->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_primary_conflicts_duplicate_upload_names_and_image_size_limits_are_enforced(): void
    {
        $sheets = ['Products' => [['product_key' => 'IMAGE', 'name' => 'Images']], 'Images' => [
            ['product_key' => 'IMAGE', 'image_key' => 'front', 'upload_name' => 'front.png', 'is_primary' => '1'],
            ['product_key' => 'IMAGE', 'image_key' => 'back', 'upload_name' => 'back.png', 'is_primary' => '1'],
        ]];
        $import = $this->submit($sheets, [$this->image(), $this->image('back.png', true)]);
        $this->prepareImport($import);
        $this->assertSame('invalid', $import->entries()->sole()->status);
        $import->update(['status' => 'failed']);
        try {
            $this->submit($sheets, [$this->image('front.png'), $this->image('FRONT.PNG', true)]);
            $this->fail('Ambiguous upload names were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }
        config(['product-import.image_pixels' => 1]);
        $next = $this->submit(['Products' => [['product_key' => 'PIXELS', 'name' => 'Pixels']], 'Images' => [['product_key' => 'PIXELS', 'image_key' => 'big', 'upload_name' => 'big.png']]], [$this->image('big.png', true)]);
        $this->prepareImport($next);
        $this->assertSame('invalid', $next->entries()->sole()->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_expired_tax_and_order_discount_are_rejected_before_start(): void
    {
        $tax = Tax::factory()->create(['effective_to' => today()->subDay()]);
        $discount = Discount::factory()->create(['scope' => 'order']);
        $import = $this->submit(['Products' => [['product_key' => 'TAX', 'name' => 'Tax'], ['product_key' => 'DISCOUNT', 'name' => 'Discount']],
            'ProductTaxes' => [['product_key' => 'TAX', 'tax_uuid' => $tax->uuid]],
            'ProductDiscounts' => [['product_key' => 'DISCOUNT', 'discount_uuid' => $discount->uuid]]]);
        $this->prepareImport($import);
        $this->assertSame(2, $import->entries()->where('status', 'invalid')->count());
        $this->assertDatabaseCount('products', 0);
    }

    public static function granularPermissions(): array
    {
        return array_map(fn (string $permission): array => [$permission], [
            'products.variants.create', 'products.update_prices', 'products.view_reference_cost', 'products.update_reference_cost',
            'products.images.create', 'products.assign_taxes', 'taxes.assign', 'products.assign_discounts', 'discounts.assign',
        ]);
    }

    #[DataProvider('granularPermissions')]
    public function test_import_permission_never_overrides_granular_permissions(string $missing): void
    {
        $tax = Tax::factory()->create();
        $discount = Discount::factory()->create();
        $actor = $this->scopedActor(array_values(array_diff([...ProductPermissions::all(), 'taxes.assign', 'discounts.assign'], [$missing])));
        $this->actor = $actor;
        $import = $this->submit([
            'Products' => [['product_key' => 'PERM', 'name' => 'Permission']],
            'Variants' => [['product_key' => 'PERM', 'variant_key' => 'base', 'sale_price' => '10', 'reference_cost' => '5']],
            'ProductTaxes' => [['product_key' => 'PERM', 'tax_uuid' => $tax->uuid]],
            'ProductDiscounts' => [['product_key' => 'PERM', 'discount_uuid' => $discount->uuid]],
            'Images' => [['product_key' => 'PERM', 'image_key' => 'front', 'upload_name' => 'front.png']],
        ], [$this->image()]);
        $this->assertSame('paused', $this->prepareImport($import)->status);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_revocation_after_preview_pauses_processing_and_worker_context_is_restored(): void
    {
        $this->actor = $this->scopedActor(['products.view', 'products.create', 'products.import']);
        $team = getPermissionsTeamId();
        $import = $this->submit(['Products' => [['product_key' => 'REVOKE', 'name' => 'Revoke']]]);
        $this->prepareImport($import);
        app(ProductImportManager::class)->start($this->actor, $import);
        $this->actor->revokePermissionTo('products.import');
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('paused', $import->fresh()->status);
        $this->assertSame($team, getPermissionsTeamId());
        $this->assertSame($this->actor->id, auth()->id());
        $this->assertDatabaseCount('products', 0);
    }

    public function test_error_download_preserves_physical_row_and_variant_key(): void
    {
        $import = $this->submit(['Products' => [['product_key' => 'ERRORS', 'name' => 'Errors']],
            'Variants' => [['product_key' => 'ERRORS', 'variant_key' => 'base', 'sale_price' => '1e6']]]);
        $this->prepareImport($import);
        $response = $this->get(route('products.import.download', ['kind' => 'errors', 'uuid' => $import->uuid]));
        $response->assertOk();
        $this->assertStringContainsString('Variants,2,errors,base,sale_price', $response->streamedContent());
    }

    public function test_automatic_product_retries_are_bounded_and_manual_retry_never_recreates_success(): void
    {
        $import = $this->submit(['Products' => [['product_key' => 'RETRY', 'name' => 'Retry']],
            'Images' => [['product_key' => 'RETRY', 'image_key' => 'front', 'upload_name' => 'front.png']]], [$this->image()]);
        $this->prepareImport($import);
        $manager = app(ProductImageManager::class);
        app()->instance(ProductImageManager::class, new class extends ProductImageManager
        {
            public function save(User $actor, Product $product, array $data, ?ProductImage $image = null): ProductImage
            {
                parent::save($actor, $product, $data, $image);
                throw new \RuntimeException('Injected retryable storage failure.');
            }
        });
        app(ProductImportManager::class)->start($this->actor, $import);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            app(ProductImportProcessor::class)->run($import->id);
        }
        $this->assertSame('completed_with_errors', $import->fresh()->status);
        $this->assertSame(3, $import->entries()->sole()->attempts);
        $this->assertDatabaseCount('products', 0);
        app()->instance(ProductImageManager::class, $manager);
        app(ProductImportManager::class)->retry($this->actor, $import);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_late_image_failure_rolls_back_group_and_retry_preserves_neighbor_success(): void
    {
        $import = $this->submit([
            'Products' => [['product_key' => 'BAD', 'name' => 'Bad'], ['product_key' => 'GOOD', 'name' => 'Good']],
            'Images' => [['product_key' => 'BAD', 'image_key' => 'front', 'upload_name' => 'front.png']],
        ], [$this->image()]);
        $this->prepareImport($import);
        $manager = app(ProductImageManager::class);
        app()->instance(ProductImageManager::class, new class extends ProductImageManager
        {
            public function save(User $actor, Product $product, array $data, ?ProductImage $image = null): ProductImage
            {
                parent::save($actor, $product, $data, $image);
                throw new \RuntimeException('Injected failure after image write.');
            }
        });
        $this->finish($import);
        $this->assertSame(['GOOD'], Product::pluck('default_sku')->all());
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
        app()->instance(ProductImageManager::class, $manager);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_import_storage_directories_are_group_shared_and_writes_remain_exclusive(): void
    {
        $root = config('product-import.root');
        $previousUmask = umask(0000);

        try {
            $import = new ProductImport(['uuid' => (string) Str::uuid()]);
            $storage = app(ProductImportStorage::class);
            $directory = $storage->directory($import);
            $parserDirectory = $storage->parserDirectory($import);

            if (PHP_OS_FAMILY !== 'Windows') {
                foreach ([$root, $directory, $parserDirectory] as $sharedDirectory) {
                    $permissions = fileperms($sharedDirectory);
                    $this->assertSame(0770, $permissions & 0777);
                    $this->assertSame(0, $permissions & 0007);
                }
            }

            $path = $storage->path($import, 'source.xlsx');
            $storage->write($path, 'original');
            $stagedName = (string) Str::uuid().'.asset';
            $storage->stage($import, $this->image(), $stagedName);
            $stagedPath = $storage->path($import, $stagedName);

            if (PHP_OS_FAMILY !== 'Windows') {
                foreach ([$path, $stagedPath] as $privateFile) {
                    $permissions = fileperms($privateFile);
                    $this->assertSame(0660, $permissions & 0777);
                    $this->assertSame(0, $permissions & 0007);
                }
            }

            try {
                $storage->write($path, 'replacement');
                $this->fail('Existing import files must not be overwritten.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Import file could not be allocated.', $exception->getMessage());
            }

            try {
                $storage->path($import, '../outside');
                $this->fail('Import paths must reject traversal segments.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Unsafe import filename.', $exception->getMessage());
            }

            try {
                $storage->directory(new ProductImport(['uuid' => '../outside']));
                $this->fail('Import directories must require a UUID identity.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Invalid import identity.', $exception->getMessage());
            }
        } finally {
            umask($previousUmask);
            File::deleteDirectory($root);
        }
    }

    public function test_inherited_acl_allows_fpm_and_worker_users_to_share_private_import_files(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0
            || ! function_exists('posix_getpwnam') || posix_getpwnam('www-data') === false || posix_getpwnam('deploy') === false) {
            $this->markTestSkipped('Cross-user ACL integration requires Linux root and the www-data and deploy accounts.');
        }

        foreach (['setfacl', 'getfacl', 'runuser', 'php'] as $command) {
            $probe = new Process(['sh', '-lc', 'command -v '.$command]);
            if (! $probe->run() || $probe->getExitCode() !== 0) {
                $this->markTestSkipped('Cross-user ACL integration requires setfacl, getfacl, runuser, and PHP CLI.');
            }
        }

        $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'product-import-acl-'.Str::uuid();
        config(['product-import.root' => $root]);
        File::makeDirectory($root, 0700, true);
        try {
            (new Process(['setfacl', '-m', 'u:www-data:rwx,u:deploy:rwx,g::rwx,m:rwx,o:---,d:u:www-data:rwx,d:u:deploy:rwx,d:g::rwx,d:m:rwx,d:o:---', $root]))->mustRun();

            $import = new ProductImport(['uuid' => (string) Str::uuid()]);
            $storage = app(ProductImportStorage::class);
            $storage->directory($import);
            $parserDirectory = $storage->parserDirectory($import);
            $acl = (new Process(['getfacl', '-cp', $parserDirectory]))->mustRun()->getOutput();
            $this->assertStringContainsString('default:user:www-data:rwx', $acl);
            $this->assertStringContainsString('default:user:deploy:rwx', $acl);
            $this->assertStringContainsString('default:mask::rwx', $acl);

            $workerFile = $parserDirectory.DIRECTORY_SEPARATOR.'worker-created.xlsx';
            $create = '$stream=fopen($argv[1],"xb"); if ($stream===false || fwrite($stream,"worker")!==6) { exit(1); } fclose($stream);';
            (new Process(['runuser', '-u', 'deploy', '--', 'php', '-r', $create, $workerFile]))->mustRun();
            $read = 'if (file_get_contents($argv[1]) !== "worker") { exit(1); }';
            (new Process(['runuser', '-u', 'www-data', '--', 'php', '-r', $read, $workerFile]))->mustRun();

            $fpmFile = $parserDirectory.DIRECTORY_SEPARATOR.'fpm-created.xlsx';
            $createFpmFile = '$stream=fopen($argv[1],"xb"); if ($stream===false || fwrite($stream,"fpm")!==3) { exit(1); } fclose($stream);';
            (new Process(['runuser', '-u', 'www-data', '--', 'php', '-r', $createFpmFile, $fpmFile]))->mustRun();
            $readFpmFile = 'if (file_get_contents($argv[1]) !== "fpm") { exit(1); }';
            (new Process(['runuser', '-u', 'deploy', '--', 'php', '-r', $readFpmFile, $fpmFile]))->mustRun();

            foreach ([$workerFile, $fpmFile] as $sharedFile) {
                $permissions = fileperms($sharedFile);
                $this->assertSame(0660, $permissions & 0777);
                $this->assertSame(0, $permissions & 0007);
            }
        } finally {
            File::deleteDirectory($root);
        }
    }
}
