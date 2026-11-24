<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\Products\Jobs\RunProductImport;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductImageManager;
use App\Modules\Products\Services\ProductImportImageAllocation;
use App\Modules\Products\Services\ProductImportManager;
use App\Modules\Products\Services\ProductImportProcessor;
use App\Modules\Products\Services\ProductImportRecovery;
use App\Modules\Products\Services\ProductImportWorkbook;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductImportWorkerTest extends TestCase
{
    use UsesIsolatedDatabase {
        tearDownUsesIsolatedDatabase as private clearIsolatedImportDatabase;
    }

    /** The isolated benchmark process drops its entire disposable schema on shutdown, preserving append-only triggers. */
    public function tearDownUsesIsolatedDatabase(): void
    {
        if ($this->name() === 'test_pos_checkout_latency_during_a_representative_import_is_measured_in_isolation') {
            $this->assertStringStartsWith('codex_public_ids_test_treasury_', DB::connection()->getDatabaseName());

            return;
        }
        $this->clearIsolatedImportDatabase();
    }

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_treasury_', DB::connection()->getDatabaseName());
        $this->assertSame(DB::connection()->getDatabaseName(), DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'products')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        config(['product-import.root' => storage_path('framework/testing/worker-import-'.Str::uuid()), 'product-import.free_disk_bytes' => 0]);
        Storage::set('public', Storage::fake('product-import-public-'.Str::uuid()));
        Queue::fake();
    }

    private function fixture(bool $images = false, int $products = 1, int $variantsPerProduct = 1): ProductImport
    {
        $sizes = $variantsPerProduct > 1 ? Size::factory()->count($variantsPerProduct)->create() : collect();
        $path = tempnam(sys_get_temp_dir(), 'worker-import-');
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Instructions');
        $this->row($writer, [ProductImportWorkbook::VERSION]);
        foreach (ProductImportWorkbook::HEADERS as $sheet => $headers) {
            $writer->addNewSheetAndMakeItCurrent()->setName($sheet);
            $this->row($writer, $headers);
            for ($number = 1; $number <= $products; $number++) {
                if ($sheet === 'Variants' && $variantsPerProduct > 1) {
                    foreach ($sizes as $index => $size) {
                        $variant = ['product_key' => 'WORKER-'.$number, 'variant_key' => 'size-'.$index, 'size_uuid' => $size->uuid, 'sale_price' => '19.9900'];
                        $this->row($writer, array_map(fn (string $header): string => $variant[$header] ?? '', $headers));
                    }

                    continue;
                }
                $values = match ($sheet) {
                    'Products' => ['product_key' => 'WORKER-'.$number, 'name' => 'Worker product '.$number],
                    'Variants' => ['product_key' => 'WORKER-'.$number, 'variant_key' => 'base', 'sale_price' => '19.9900'],
                    'Images' => $images ? ['product_key' => 'WORKER-'.$number, 'image_key' => 'front-'.$number, 'upload_name' => 'front.png', 'is_primary' => '1'] : null,
                    default => null,
                };
                if ($values !== null) {
                    $this->row($writer, array_map(fn (string $header): string => $values[$header] ?? '', $headers));
                }
            }
        }
        $writer->close();
        $file = UploadedFile::fake()->createWithContent('worker.xlsx', file_get_contents($path));
        unlink($path);
        $uploads = [];
        if ($images) {
            $image = imagecreatetruecolor(1, 1);
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $uploads[] = UploadedFile::fake()->createWithContent('front.png', $bytes);
        }

        return app(ProductImportManager::class)->submit($this->actor, $file, $uploads);
    }

    private function row(Writer $writer, array $values): void
    {
        $writer->addRow(new Row(array_map(fn (string $value): StringCell => new StringCell($value, null), $values)));
    }

    private function worker(ProductImport $import, string $mode, string $start = '0'): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/ProductImportWorker.php')], base_path(), [
            'PI_MODE' => $mode, 'PI_IMPORT' => (string) $import->id, 'PI_ACTOR' => (string) $this->actor->id, 'PI_KEY' => 'WORKER-1',
            'PI_ROOT' => config('product-import.root'), 'PI_PUBLIC' => Storage::disk('public')->path(''), 'PI_START' => $start,
        ]);
        $process->setTimeout(45)->start();

        return $process;
    }

    private function ready(ProductImport $import): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            app(ProductImportProcessor::class)->run($import->id);
            if ($import->fresh()->status !== 'preparing') {
                break;
            }
        }
        $this->assertSame('ready', $import->fresh()->status, json_encode($import->fresh()->errors));
        app(ProductImportManager::class)->start($this->actor, $import);
    }

    public function test_hard_exit_before_commit_rolls_back_and_recovers_orphan_image_without_duplicate_attachment(): void
    {
        $import = $this->fixture(images: true);
        $this->ready($import);
        $worker = $this->worker($import, 'before_commit');
        $this->assertTrue($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, "allocated\n")));
        $worker->stop(0);
        $this->assertFalse($worker->isRunning());
        $this->assertNotSame(0, $worker->getExitCode());
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame('pending', $import->entries()->sole()->status);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
        $this->assertCount(1, glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
        DB::table('product_import_slots')->update(['lease_until' => now()->subSecond()]);
        $import->update(['lease_until' => now()->subSecond()]);
        $resumed = $this->worker($import, 'normal');
        $this->assertSame(0, $resumed->wait(), $resumed->getErrorOutput().$resumed->getOutput());
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseCount('product_images', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('products'));
        $this->assertSame([], glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
    }

    public function test_hard_exit_after_commit_and_duplicate_delivery_return_committed_result(): void
    {
        $import = $this->fixture(images: true);
        $this->ready($import);
        $worker = $this->worker($import, 'after_commit');
        $this->assertTrue($worker->waitUntil(fn (string $type, string $output): bool => str_contains($output, "committed\n")));
        $worker->stop(0);
        $this->assertFalse($worker->isRunning());
        $this->assertNotSame(0, $worker->getExitCode());
        $result = $import->entries()->sole()->result;
        $this->assertCount(1, glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
        DB::table('product_import_slots')->update(['lease_until' => now()->subSecond()]);
        $import->update(['lease_until' => now()->subSecond()]);
        app(ProductImportRecovery::class)->recover();
        $replay = $this->worker($import, 'normal');
        $this->assertSame(0, $replay->wait(), $replay->getErrorOutput());
        $this->assertSame([], glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
        $this->assertSame($result, $import->entries()->sole()->result);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_two_worker_deliveries_and_concurrent_manual_creation_never_duplicate_product(): void
    {
        $import = $this->fixture();
        $this->ready($import);
        $start = (string) (microtime(true) + 2);
        $processes = [$this->worker($import, 'normal', $start), $this->worker($import, 'normal', $start), $this->worker($import, 'manual', $start)];
        foreach ($processes as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
        }
        $this->assertSame(1, Product::where('default_sku', 'WORKER-1')->count());
        $this->assertContains($import->entries()->sole()->status, ['succeeded', 'failed']);
    }

    public function test_repeated_abandoned_leases_pause_instead_of_restarting_forever(): void
    {
        $import = $this->fixture();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $import->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->subMinute(), 'updated_at' => now()->subMinutes(5)]);
            app(ProductImportRecovery::class)->recover();
        }
        $this->assertSame('paused', $import->fresh()->status);
        $this->assertSame(3, $import->fresh()->recovery_attempts);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_recovery_redispatches_lost_work_and_expired_preview_purges_only_private_assets(): void
    {
        $import = $this->fixture();
        $import->update(['updated_at' => now()->subMinutes(5)]);
        app(ProductImportRecovery::class)->recover();
        Queue::assertPushed(RunProductImport::class);
        $import->update(['expires_at' => now()->subSecond()]);
        app(ProductImportRecovery::class)->recover();
        $this->assertSame('expired', $import->fresh()->status);
        $this->assertNotNull($import->fresh()->purged_at);
        $this->assertFileDoesNotExist(config('product-import.root').'/'.$import->uuid.'/source.xlsx');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_reconciliation_preserves_backup_protected_orphans_and_committed_product_images(): void
    {
        $import = $this->fixture(images: true);
        $this->ready($import);
        app(ProductImportProcessor::class)->run($import->id);
        $image = Product::sole()->images()->sole();
        $orphan = app(ProductImportImageAllocation::class)->during($import,
            fn (): string => app(ProductImageManager::class)->storeImmutableUpload(UploadedFile::fake()->image('orphan.png'), 'products/'.$image->product_id));
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach (['products', 'products/'.$image->product_id] as $directory) {
                $permissions = fileperms(Storage::disk('public')->path($directory));
                $this->assertSame(0770, $permissions & 0777);
                $this->assertSame(0, $permissions & 0007);
            }
        }
        $run = BackupRun::factory()->create(['status' => 'available', 'verification_status' => 'checksum_verified', 'verified_at' => now(), 'completed_at' => now(),
            'object_key' => 'synthetic/import-fixture.fhbk', 'size_bytes' => 1, 'ciphertext_checksum' => str_repeat('a', 64), 'manifest_checksum' => str_repeat('b', 64),
            'destination_snapshot' => ['media_reference_version' => 1, 'media_references' => ['files/public/'.$orphan]]]);

        app(ProductImportRecovery::class)->recover();

        Storage::disk('public')->assertExists($orphan);
        Storage::disk('public')->assertExists($image->path);
        $this->assertCount(1, glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
        $run->update(['destination_snapshot' => ['media_reference_version' => 1, 'media_references' => []]]);

        app(ProductImportRecovery::class)->recover();

        Storage::disk('public')->assertMissing($orphan);
        Storage::disk('public')->assertExists($image->path);
        $this->assertSame([], glob(config('product-import.root').'/'.$import->uuid.'/*.allocation'));
        $this->assertDatabaseCount('product_images', 1);
    }

    public function test_compact_history_and_idempotency_survive_source_expiry_indefinitely(): void
    {
        $import = $this->fixture(images: true);
        $this->ready($import);
        app(ProductImportProcessor::class)->run($import->id);
        $result = $import->entries()->sole()->result;
        $fingerprint = $import->fingerprint;
        $import->update(['expires_at' => now()->subDays(400), 'finished_at' => now()->subDays(430)]);

        app(ProductImportRecovery::class)->recover();
        app(ProductImportRecovery::class)->recover();

        $this->assertNotNull($import->fresh()->purged_at);
        $this->assertSame($fingerprint, $import->fresh()->fingerprint);
        $entry = $import->entries()->sole();
        $this->assertSame('succeeded', $entry->status);
        $this->assertSame($result, $entry->result);
        $this->assertSame([], $entry->payload);
        $this->assertSame(1, $entry->variant_count);
        $this->assertSame(1, $entry->image_count);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_images', 1);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_pos_checkout_latency_during_a_representative_import_is_measured_in_isolation(): void
    {
        Module::whereIn('code', ['pos', 'payments', 'sales', 'treasury', 'inventory', 'inventory_costing', 'products', 'purchasing', 'taxes', 'discounts'])->update(['is_active' => true]);
        app()->forgetInstance(DocumentNumberService::class);
        $branch = Location::create(['name' => 'Import latency branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($branch);
        app(LocationContext::class)->setFor($this->actor, $branch->id);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true, 'has_fixed_selling_price' => true, 'sale_price' => '100']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
            'location_id' => $branch->id, 'order_date' => today()->toDateString(), 'items' => [['product_id' => $product->id, 'variant_id' => $variant->id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'], 'quantity' => '10', 'unit_price' => '20']]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => '10', 'unit_cost' => '20']]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $this->actor->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $devices = app(PosDeviceManagement::class);
        $device = $devices->register($this->actor, ['name' => 'Import benchmark register', 'location_id' => $branch->id]);
        $devices->configurePaymentMethod($this->actor, $device, $method->id, $cash->id);
        $shift = app(PosShiftService::class)->open($this->actor, $device->id, $cash->id, '0', (string) Str::uuid7());
        $payload = ['items' => [['variant_id' => $variant->id, 'quantity' => '1']], 'tenders' => [['payment_method_id' => $method->id, 'amount' => '100']], 'cash_received' => '100'];
        $sample = function () use ($shift, $payload): float {
            $started = microtime(true);
            $transaction = app(PosCheckoutService::class)->checkout($this->actor, $shift->id, (string) Str::uuid7(), $payload);
            $this->assertSame('completed', $transaction->sync_status);

            return (microtime(true) - $started) * 1000;
        };
        $baseline = [];
        for ($number = 0; $number < 5; $number++) {
            $baseline[] = $sample();
        }
        $import = $this->fixture(images: true, products: 30);
        $this->ready($import);
        $worker = $this->worker($import, 'normal');
        $concurrent = [];
        for ($number = 0; $number < 5; $number++) {
            $concurrent[] = $sample();
        }
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
        $this->assertGreaterThan(0, $import->entries()->where('status', 'succeeded')->count());
        sort($baseline);
        sort($concurrent);
        $measurements = ['samples_per_scenario' => 5, 'baseline_median_ms' => round($baseline[2], 2),
            'baseline_p95_ms' => round($baseline[4], 2), 'concurrent_median_ms' => round($concurrent[2], 2), 'concurrent_p95_ms' => round($concurrent[4], 2),
            'p95_change_percent' => round(($concurrent[4] / $baseline[4] - 1) * 100, 2)];
        file_put_contents(storage_path('framework/testing/product-import-pos-measurements-'.DB::connection()->getDatabaseName().'.json'), json_encode($measurements, JSON_THROW_ON_ERROR));
    }

    public function test_maximum_row_shape_is_staged_once_and_validation_checkpoints_are_resumable(): void
    {
        $import = $this->fixture(products: 2000, variantsPerProduct: 5);
        $started = microtime(true);
        app(ProductImportProcessor::class)->run($import->id);
        $elapsed = microtime(true) - $started;
        $this->assertSame('preparing', $import->fresh()->status);
        $this->assertSame('validate', $import->fresh()->preparation_phase);
        $this->assertSame(12000, $import->rows()->count());
        $this->assertSame(25, $import->entries()->where('status', 'valid')->count());
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame(50, $import->entries()->where('status', 'valid')->count());
        $this->assertSame(1, $import->fresh()->preparation_attempts);
        $this->assertDatabaseCount('products', 0);
        fwrite(STDERR, 'MAXIMUM_SHAPE_MEASUREMENTS '.json_encode(['products' => 2000, 'variants' => 10000,
            'parse_and_first_25_validation_seconds' => round($elapsed, 3), 'peak_php_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_largest_allowed_product_group_is_not_split_across_commits(): void
    {
        $import = $this->fixture(images: true, variantsPerProduct: 100);
        $this->ready($import);
        $started = microtime(true);
        app(ProductImportProcessor::class)->run($import->id);
        $elapsed = microtime(true) - $started;
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 100);
        $this->assertDatabaseCount('product_images', 1);
        $this->assertLessThan(120, $elapsed);
        fwrite(STDERR, 'LARGEST_GROUP_MEASUREMENTS '.json_encode(['variants' => 100, 'images' => 1, 'processing_seconds' => round($elapsed, 3),
            'peak_php_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function test_representative_import_records_query_time_memory_and_chunk_boundaries(): void
    {
        $import = $this->fixture(images: true, products: 30);
        $queries = 0;
        $listening = false;
        $transactions = [];
        $transactionStarted = null;
        $createdProduct = false;
        Event::listen(TransactionBeginning::class, function ($event) use (&$transactionStarted, &$createdProduct, &$listening): void {
            if ($listening && $event->connection->transactionLevel() === 1) {
                $transactionStarted = microtime(true);
                $createdProduct = false;
            }
        });
        Event::listen('eloquent.created: '.Product::class, function () use (&$createdProduct): void {
            $createdProduct = true;
        });
        Event::listen(TransactionCommitted::class, function ($event) use (&$transactions, &$transactionStarted, &$createdProduct, &$listening): void {
            if ($listening && $event->connection->transactionLevel() === 0 && $createdProduct && $transactionStarted !== null) {
                $transactions[] = (microtime(true) - $transactionStarted) * 1000;
                $transactionStarted = null;
                $createdProduct = false;
            }
        });
        $lockStatus = fn (): array => collect(DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_deadlocks')"))->pluck('Value', 'Variable_name')->all();
        $locksBefore = $lockStatus();
        DB::listen(function () use (&$queries, &$listening): void {
            if ($listening) {
                $queries++;
            }
        });
        $preparation = microtime(true);
        $this->ready($import);
        $preparation = microtime(true) - $preparation;
        $listening = true;
        $started = microtime(true);
        app(ProductImportProcessor::class)->run($import->id);
        $this->assertSame(25, $import->entries()->where('status', 'succeeded')->count());
        app(ProductImportProcessor::class)->run($import->id);
        $duration = microtime(true) - $started;
        $listening = false;
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertDatabaseCount('products', 30);
        $this->assertDatabaseCount('product_variants', 30);
        $this->assertDatabaseCount('product_images', 30);
        sort($transactions);
        $locksAfter = $lockStatus();
        $measurements = ['products' => 30, 'variants' => 30, 'images' => 30,
            'preparation_seconds' => round($preparation, 3), 'processing_seconds' => round($duration, 3),
            'products_per_minute' => round(1800 / $duration, 2), 'queries' => $queries, 'queries_per_product' => round($queries / 30, 2),
            'peak_php_bytes' => memory_get_peak_usage(true), 'product_transaction_samples' => count($transactions),
            'transaction_median_ms' => $transactions === [] ? null : round($transactions[(int) floor(count($transactions) / 2)], 2),
            'transaction_p95_ms' => $transactions === [] ? null : round($transactions[min(count($transactions) - 1, (int) ceil(count($transactions) * .95) - 1)], 2),
            'row_lock_waits' => isset($locksAfter['Innodb_row_lock_waits']) ? (int) $locksAfter['Innodb_row_lock_waits'] - (int) $locksBefore['Innodb_row_lock_waits'] : null,
            'deadlocks' => isset($locksAfter['Innodb_deadlocks']) ? (int) $locksAfter['Innodb_deadlocks'] - (int) ($locksBefore['Innodb_deadlocks'] ?? 0) : null,
            'retried_product_groups' => $import->entries()->where('attempts', '>', 1)->count(), 'failed_product_groups' => $import->entries()->where('status', 'failed')->count(),
            'failed_jobs' => DB::table('failed_jobs')->where('queue', 'products')->count()];
        file_put_contents(storage_path('framework/testing/product-import-measurements-'.DB::connection()->getDatabaseName().'.json'), json_encode($measurements, JSON_THROW_ON_ERROR));
        fwrite(STDERR, 'IMPORT_MEASUREMENTS '.json_encode($measurements, JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
