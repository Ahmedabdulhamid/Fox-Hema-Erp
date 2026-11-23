<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\BackupRestore\Services\QueryWriteGuard;
use App\Modules\BackupRestore\Services\WriteAdmission;
use App\Modules\BackupRestore\Services\WriteCoordinationStore;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosOfflineSaleSyncService;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\POS\Services\PosTerminalCatalog;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BaselineWriteAdmission;
use Tests\TestCase;

class BackupBusinessPerformanceTest extends TestCase
{
    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private ProductVariant $unvaluedVariant;

    private TreasuryAccount $cash;

    private TreasuryAccount $treasuryCash;

    private PaymentMethod $method;

    private PosSession $shift;

    private string $originalDatabase;

    private string $benchmarkDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sync', config('queue.default'));
        $this->originalDatabase = DB::connection()->getDatabaseName();
        $this->benchmarkDatabase = $this->originalDatabase.'_bench';
        $this->assertStringStartsWith('codex_public_ids_test_', $this->benchmarkDatabase);
        DB::statement('CREATE DATABASE `'.$this->benchmarkDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.mysql.database' => $this->benchmarkDatabase]);
        DB::purge('mysql');
        $this->assertSame($this->benchmarkDatabase, DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->app->forgetInstance(DocumentNumberService::class);
        app()->setLocale('en');
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        Http::preventStrayRequests();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->branch = Location::create(['name' => 'Synthetic performance branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($this->actor, (int) $this->branch->id);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true, 'has_fixed_selling_price' => true, 'sale_price' => '100']);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        $this->unvaluedVariant = ProductVariant::factory()->create(['is_active' => true]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $product->id, 'variant_id' => $this->variant->id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'], 'quantity' => '1000', 'unit_price' => '20']]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => '1000', 'unit_cost' => '20']]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->treasuryCash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $devices = app(PosDeviceManagement::class);
        $device = $devices->register($this->actor, ['name' => 'Synthetic performance register', 'location_id' => $this->branch->id]);
        $devices->configurePaymentMethod($this->actor, $device, (int) $this->method->id, (int) $this->cash->id);
        $this->shift = app(PosShiftService::class)->open($this->actor, (int) $device->id, (int) $this->cash->id, '0', (string) Str::uuid7());
        app(QueryWriteGuard::class)->install();
        app(WriteCoordinationStore::class)->connection();
    }

    protected function tearDown(): void
    {
        config(['backup-restore.write_admission_enabled' => false]);
        app(WriteAdmission::class)->finish();
        DB::purge('backup_write_coordination');
        if (isset($this->benchmarkDatabase)) {
            $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
            $this->assertStringStartsWith('codex_public_ids_test_', $this->benchmarkDatabase);
            config(['database.connections.mysql.database' => $this->originalDatabase]);
            DB::purge('mysql');
            DB::statement('DROP DATABASE `'.$this->benchmarkDatabase.'`');
        }
        parent::tearDown();
    }

    public function test_actual_business_services_with_commits_under_disabled_and_enabled_admission(): void
    {
        $results = [];
        $queryCount = null;
        $profile = null;
        Event::listen([
            TransactionBeginning::class,
            TransactionCommitted::class,
            TransactionRolledBack::class,
        ], function ($event) use (&$profile): void {
            if ($profile !== null) {
                $eventName = class_basename($event).'/'.$event->connectionName.'/'.$event->connection->transactionLevel();
                $profile['transaction_events'][$eventName] = ($profile['transaction_events'][$eventName] ?? 0) + 1;
            }
        });
        DB::listen(function ($query) use (&$queryCount, &$profile): void {
            if ($queryCount !== null) {
                $queryCount['total']++;
                if ($query->connectionName === 'backup_write_coordination') {
                    $queryCount['coordination']++;
                }
            }
            if ($profile !== null) {
                $category = WriteAdmissionPerformanceTest::category($query->sql, $query->connectionName);
                $phase = 'business';
                $context = [];
                $path = [];
                $nested = false;
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 120) as $frame) {
                    $class = $frame['class'] ?? '';
                    $method = $frame['function'];
                    if (in_array($class, [WriteAdmission::class, BaselineWriteAdmission::class], true)) {
                        if ($method === 'assertHeld') {
                            $phase = 'ownership_check';
                        } elseif ($method === 'begin') {
                            $nested = $phase === 'ownership_check';
                            if (! $nested) {
                                $phase = 'acquire';
                            }
                        } elseif ($method === 'finish') {
                            $phase = 'release';
                        }
                    }
                    if (str_contains($class, 'Observers\\')) {
                        $context['observer'] = true;
                    }
                    if (str_contains($class, 'Jobs\\') || $class === 'Illuminate\\Queue\\SyncQueue') {
                        $context['sync_job'] = true;
                    }
                    if ($class === 'Illuminate\\Database\\DatabaseTransactionRecord' && $method === 'executeCallbacks') {
                        $context['after_commit'] = true;
                    }
                    if (str_starts_with($class, 'App\\Modules\\') && ! str_contains($class, '\\BackupRestore\\') && $method !== '{closure}' && count($path) < 6) {
                        $path[$class.'::'.$method] = true;
                    }
                }
                if ($nested) {
                    $phase = 'nested_acquire_check';
                }
                $context = array_keys($context);
                sort($context);
                $group = $phase.'/'.$category.'/'.implode('+', $context);
                $profile['categories'][$group] = ($profile['categories'][$group] ?? 0) + 1;
                $call = $group.'/'.implode(' > ', array_keys($path));
                $profile['call_paths'][$call] = ($profile['call_paths'][$call] ?? 0) + 1;
                $depth = $query->connectionName.'/'.$query->connection->transactionLevel();
                $profile['query_transaction_depths'][$depth] = ($profile['query_transaction_depths'][$depth] ?? 0) + 1;
            }
        });
        $profiles = [];
        foreach (['sales_invoice_posting', 'treasury_posting', 'inventory_posting', 'pos_synchronization'] as $scenario) {
            $samples = ['disabled' => [], 'before' => [], 'after' => []];
            foreach (['before', 'after'] as $mode) {
                config(['backup-restore.write_admission_enabled' => false]);
                $operation = $this->prepare($scenario);
                RequestAuthorizationFacts::forget();
                $this->app->instance(WriteAdmission::class, $mode === 'before' ? new BaselineWriteAdmission : new WriteAdmission);
                config(['backup-restore.write_admission_enabled' => true]);
                $profile = ['categories' => [], 'call_paths' => [], 'query_transaction_depths' => []];
                try {
                    app(WriteAdmission::class)->run('service', $operation);
                } finally {
                    $profiles[$scenario][$mode] = $profile;
                    $profile = null;
                    config(['backup-restore.write_admission_enabled' => false]);
                }
            }
            for ($sample = 0; $sample < 10; $sample++) {
                $modes = ['disabled', 'before', 'after'];
                $offset = $sample % 3;
                foreach ([...array_slice($modes, $offset), ...array_slice($modes, 0, $offset)] as $mode) {
                    config(['backup-restore.write_admission_enabled' => false]);
                    $operation = $this->prepare($scenario);
                    RequestAuthorizationFacts::forget();
                    $this->app->instance(WriteAdmission::class, $mode === 'before' ? new BaselineWriteAdmission : new WriteAdmission);
                    config(['backup-restore.write_admission_enabled' => $mode !== 'disabled']);
                    $queryCount = ['total' => 0, 'coordination' => 0];
                    $started = hrtime(true);
                    try {
                        $value = app(WriteAdmission::class)->run('service', $operation);
                    } finally {
                        $elapsed = (hrtime(true) - $started) / 1_000_000;
                        $counts = $queryCount;
                        $queryCount = null;
                        config(['backup-restore.write_admission_enabled' => false]);
                    }
                    $samples[$mode][] = ['milliseconds' => $elapsed, 'queries' => $counts['total'], 'coordination_queries' => $counts['coordination']];
                    $this->assertSame(0, DB::transactionLevel(), 'Business operations must commit without an artificial outer transaction.');
                    $this->assertNotNull($value);
                    if ($scenario === 'pos_synchronization') {
                        $this->assertSame('completed', $value['status']);
                    }
                    $this->assertDatabaseCount('backup_write_operations', 0);
                }
            }
            $disabled = $this->statistics($samples['disabled']);
            $before = $this->statistics($samples['before']);
            $after = $this->statistics($samples['after']);
            $this->assertLessThan($before['median_queries'], $after['median_queries']);
            $this->assertLessThan($before['median_coordination_queries'], $after['median_coordination_queries']);
            $results[$scenario] = ['disabled' => $disabled, 'before' => $before, 'after' => $after,
                'before_query_overhead' => $before['median_queries'] - $disabled['median_queries'],
                'after_query_overhead' => $after['median_queries'] - $disabled['median_queries'],
                'query_overhead_reduction_percent' => round(100 * ($before['median_queries'] - $after['median_queries']) / ($before['median_queries'] - $disabled['median_queries']), 2),
                'enabled_median_improvement_percent' => round(100 * ($before['median_ms'] - $after['median_ms']) / $before['median_ms'], 2),
                'raw_samples' => $samples];
        }
        file_put_contents(storage_path('framework/testing/phase6c3a/query-profiles.json'), json_encode($profiles, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents(storage_path('framework/testing/phase6c3a/business-performance.json'), json_encode(['sample_count_per_condition' => 10, 'queue_driver' => config('queue.default'),
            'workload' => 'Actual business services, single item, EGP amounts; fresh synthetic document per sample; paired alternating conditions',
            'includes' => 'Admission acquisition/release, actual business transaction commit and after-commit work; fixture preparation excluded',
            'limitations' => ['Local disposable MariaDB, single process, warm schema, growing synthetic data set', 'P95 is the maximum of ten observations, not a production tail estimate', 'No capture contention or external writers'],
            'profiling' => 'One untimed operation per enabled implementation; stack profiling is disabled during all latency samples',
            'baseline_implementation_sha256' => hash_file('sha256', storage_path('framework/testing/phase6c3a/original-WriteAdmission.php')),
            'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function prepare(string $scenario): Closure
    {
        if ($scenario === 'treasury_posting') {
            $service = app(TreasuryService::class);
            $draft = $service->createDraft($this->actor, ['type' => 'cash_deposit', 'account_id' => $this->treasuryCash->id, 'amount' => '12.3456',
                'effective_date' => today()->toDateString(), 'source_type' => 'external_capital', 'source_reference' => 'SYN-'.Str::uuid7(), 'reason' => 'Synthetic performance deposit'], (string) Str::uuid7());

            return fn () => $service->post($this->actor, $draft);
        }
        if ($scenario === 'inventory_posting') {
            $command = new StockPostingCommand($this->unvaluedVariant, $this->branch, StockMovementType::Purchase, '1', (string) Str::uuid7());

            return fn () => app(InventoryPostingService::class)->post($command, $this->actor);
        }
        if ($scenario === 'pos_synchronization') {
            $quote = app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id, [$this->variant->id => ['quantity' => '1', 'discount_id' => null]]);
            $quote['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip(['variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'])), $quote['items']);
            $envelope = ['client_transaction_id' => (string) Str::uuid7(), 'scope' => ['branch_id' => (int) $this->branch->id, 'device_id' => (int) $this->shift->pos_device_id, 'session_id' => (int) $this->shift->id, 'cashier_id' => (int) $this->actor->id],
                'sale' => ['items' => [['variant_id' => $this->variant->id, 'quantity' => '1']], 'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']], 'cash_received' => '100'], 'quote' => $quote];
            $device = $this->shift->posDevice;

            return fn () => app(PosOfflineSaleSyncService::class)->synchronize($this->actor, $device, $envelope);
        }
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->actor, ['location_id' => $this->branch->id, 'customer_name' => 'Synthetic performance customer', 'items' => [[
            'product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id, 'description' => 'Synthetic item', 'quantity' => '1', 'unit_price' => '100']]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($this->actor, ['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '1']]], (string) Str::uuid7());
        $delivery = app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $deliveries->revision($delivery));
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($this->actor, ['sales_order_id' => $order->id, 'invoice_date' => today()->toDateString(), 'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '1']]], (string) Str::uuid7());
        $revision = $invoices->revision($invoice);

        return fn () => $invoices->post($this->actor, $invoice, $revision);
    }

    /** @param list<array{milliseconds: float, queries: int, coordination_queries: int}> $samples
     * @return array<string, int|float>
     */
    private function statistics(array $samples): array
    {
        $times = array_column($samples, 'milliseconds');
        $queries = array_column($samples, 'queries');
        $coordination = array_column($samples, 'coordination_queries');
        sort($times);
        sort($queries);
        sort($coordination);

        return ['median_ms' => round(($times[4] + $times[5]) / 2, 3), 'p95_ms' => round($times[9], 3),
            'median_queries' => ($queries[4] + $queries[5]) / 2, 'p95_queries' => $queries[9],
            'median_coordination_queries' => ($coordination[4] + $coordination[5]) / 2];
    }
}
