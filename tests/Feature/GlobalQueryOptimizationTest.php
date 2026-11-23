<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Http\Middleware\SetLocationPermissionContext;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentsAccess;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Filament\Resources\Products\Pages\ViewProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Purchasing\Filament\VendorBillActions;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorPaymentAccess;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Sales\Filament\SalesCreditNoteActions;
use App\Modules\Sales\Filament\SalesDeliveryActions;
use App\Modules\Sales\Filament\SalesInvoiceActions;
use App\Modules\Sales\Filament\SalesOrderActions;
use App\Modules\Sales\Filament\SalesReturnActions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesProductSelection;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Taxes\Services\DefinitionAccess;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class GlobalQueryOptimizationTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $admin;

    private User $reader;

    private Location $branch;

    /** @var array<string, Model> */
    private array $records = [];

    /** @var array<string, Model> */
    private array $drafts = [];

    /** @var list<array{sql: string, binding_hash: string, ms: float, source: list<string>}> */
    private array $queries = [];

    /** @var array<string, list<string>> */
    private array $sources = [];

    /** @var array<string, int> */
    private array $cacheOperations = [];

    private bool $recording = false;

    private ?User $profileActor = null;

    private string $profileDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        config(['debugbar.enabled' => false]);
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        Event::listen([
            CacheHit::class,
            CacheMissed::class,
            KeyWritten::class,
            KeyWriteFailed::class,
            KeyForgotten::class,
            KeyForgetFailed::class,
        ], function (object $event): void {
            if (! $this->recording) {
                return;
            }

            $operation = match (true) {
                $event instanceof CacheHit => 'hit',
                $event instanceof CacheMissed => 'miss',
                $event instanceof KeyWritten => 'write',
                $event instanceof KeyForgotten => 'forget',
                default => 'failure',
            };
            $this->cacheOperations[$operation] = ($this->cacheOperations[$operation] ?? 0) + 1;
        });
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = $this->provisionTestSuperAdmin();
        $this->admin->forceFill(['must_change_password' => false])->save();
        $this->branch = Location::create(['name' => 'Benchmark Branch 1', 'type' => 'branch', 'is_active' => true]);
        foreach (['Benchmark Branch 2' => 'branch', 'Benchmark Branch 3' => 'branch', 'Benchmark Warehouse' => 'warehouse'] as $name => $type) {
            Location::create(['name' => $name, 'type' => $type, 'is_active' => true]);
        }
        $this->reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->reader->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->reader->givePermissionTo(Permission::where('guard_name', 'web')->where('name', 'not like', '%all_locations%')->pluck('name')->all());
        $this->actingAs($this->admin);
        app(LocationContext::class)->setFor($this->admin, $this->branch->id);
        $phase = preg_replace('/[^a-zA-Z0-9_-]/', '', getenv('QUERY_PROFILE_PHASE') ?: 'current');
        $this->profileDirectory = storage_path('framework/testing/global-query-'.$phase);
        if (! is_dir($this->profileDirectory)) {
            mkdir($this->profileDirectory, 0777, true);
        }
        DB::listen(function (QueryExecuted $event): void {
            if (! $this->recording) {
                return;
            }
            if (! isset($this->sources[$event->sql])) {
                $source = [];
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 70) as $frame) {
                    $file = str_replace('\\', '/', $frame['file'] ?? '');
                    if (str_contains($file, '/app/') && ! str_contains($file, '/vendor/') && ! str_contains($file, '/tests/')) {
                        $source[] = str_replace(str_replace('\\', '/', base_path()).'/', '', $file).':'.($frame['line'] ?? 0);
                    }
                }
                $this->sources[$event->sql] = array_values(array_unique($source));
            }
            $this->queries[] = ['sql' => $event->sql, 'binding_hash' => hash('sha256', serialize($event->bindings)),
                'ms' => (float) $event->time, 'source' => $this->sources[$event->sql]];
        });
    }

    private function rememberRecord(Model $record): void
    {
        $this->records[$record::class] ??= $record;
        if ($record->getAttribute('status') === 'draft') {
            $this->drafts[$record::class] ??= $record;
        }
    }

    private function fixtures(int $count, bool $includeEditableDrafts = false): void
    {
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        $vendorCash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->admin, $treasury->createDraft($this->admin, ['type' => 'opening_balance',
            'account_id' => $vendorCash->id, 'amount' => '1000000', 'effective_date' => now()->toDateString(),
            'reason' => 'Disposable query benchmark funds'], (string) Str::uuid7()));
        $categories = [];
        foreach (['fifo', 'avco'] as $costingMethod) {
            $category = Category::factory()->create(['name' => 'Benchmark '.$costingMethod]);
            InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => $costingMethod]);
            $categories[] = $category;
            $this->rememberRecord($category);
        }
        $brand = Brand::factory()->create();
        $color = Color::factory()->create();
        $size = Size::factory()->create();
        foreach ([$brand, $color, $size, $method, $vendorCash] as $record) {
            $this->rememberRecord($record);
        }
        for ($index = 0; $index < $count; $index++) {
            $supplier = Supplier::factory()->create(['name' => 'Benchmark Supplier '.$index]);
            $product = Product::factory()->create(['name' => 'Benchmark Sneaker '.$index, 'category_id' => $categories[$index % 2]->id,
                'brand_id' => $brand->id, 'status' => 'active', 'is_active' => true]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'color_id' => $color->id,
                'size_id' => $size->id, 'sale_price' => '120.1234']);
            $this->configureSalesPricing($variant);
            $orders = app(PurchaseOrderService::class);
            $purchase = $orders->create($this->admin, ['supplier_id' => $supplier->id, 'location_id' => $this->branch->id,
                'order_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'variant_id' => $variant->id,
                    'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'],
                    'quantity' => '300', 'unit_price' => '2']]], (string) Str::uuid7());
            $purchase = $orders->confirm($this->admin, $purchase, $orders->revision($purchase));
            $receipts = app(PurchaseReceiptService::class);
            $receipt = $purchase->receipts()->firstOrFail();
            $receipt = $receipts->update($this->admin, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
                'quantity' => '300', 'unit_cost' => '2']]], $receipts->revision($receipt));
            $receipt = $receipts->post($this->admin, $receipt, $receipts->revision($receipt));
            $bill = VendorBill::factory()->create(['supplier_id' => $supplier->id, 'purchase_order_id' => $purchase->id,
                'subtotal' => '600', 'total_amount' => '600', 'created_by' => $this->admin->id]);
            DB::table('vendor_bills')->where('id', $bill->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->admin->id]);
            $bill->refresh();
            $vendorPayments = app(VendorPaymentService::class);
            $vendorPayment = $vendorPayments->post($this->admin, $vendorPayments->createDraft($this->admin, [
                'supplier_id' => $supplier->id, 'location_id' => $this->branch->id, 'payment_method_id' => $method->id,
                'treasury_account_id' => $vendorCash->id, 'payment_date' => now()->toDateString(), 'amount' => '10',
            ], (string) Str::uuid7()));
            $order = SalesOrder::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id,
                'customer_name' => 'Benchmark Customer '.$index, 'subtotal' => '1200', 'total_amount' => '1200']);
            SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'variant_id' => $variant->id]);
            $customer = app(CustomerAccounts::class)->save($this->admin, ['location_id' => $this->branch->id, 'name' => 'Benchmark Customer '.$index]);
            $invoice = SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id,
                'subtotal' => '100.0001', 'total_amount' => '100.0001']);
            DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->admin->id]);
            $invoice->refresh();
            app(CustomerAccounts::class)->linkInvoice($this->admin, $customer, $invoice, [], (string) Str::uuid7());
            $cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
            $payment = app(CustomerPayments::class)->payInvoice($this->admin, $invoice, ['payment_method_id' => $method->id,
                'treasury_account_id' => $cash->id, 'amount' => '40', 'payment_date' => now()->toDateString()], (string) Str::uuid7());
            foreach ([$supplier, $product, $variant, $purchase, $receipt, $bill, $vendorPayment, $order, $invoice, $customer, $payment] as $record) {
                $this->rememberRecord($record);
            }
        }
        $this->salesCycle($method, $includeEditableDrafts);
        $this->rememberRecord(SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]));
        $purchase = $this->records[PurchaseOrder::class];
        $this->rememberRecord(VendorBill::factory()->create(['supplier_id' => $purchase->supplier_id,
            'purchase_order_id' => $purchase->id, 'created_by' => $this->admin->id]));
        $data = ['supplier_id' => $purchase->supplier_id, 'location_id' => $this->branch->id, 'order_date' => now()->toDateString(),
            'items' => [['product_id' => $this->records[Product::class]->id, 'variant_id' => $this->records[ProductVariant::class]->id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->records[ProductVariant::class])['receiving_mode'],
                'quantity' => '10', 'unit_price' => '2']]];
        $orders = app(PurchaseOrderService::class);
        $this->rememberRecord($orders->create($this->admin, $data, (string) Str::uuid7()));
        $receiptOrder = $orders->create($this->admin, $data, (string) Str::uuid7());
        $receiptOrder = $orders->confirm($this->admin, $receiptOrder, $orders->revision($receiptOrder));
        $this->rememberRecord($receiptOrder->receipts()->firstOrFail());
    }

    private function salesCycle(PaymentMethod $method, bool $includeEditableDrafts = false): void
    {
        $variant = $this->records[ProductVariant::class];
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->admin, ['location_id' => $this->branch->id, 'customer_name' => 'Benchmark complete sale',
            'items' => [['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '120.123456']]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->admin, $order);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($this->admin, ['sales_order_id' => $order->id, 'items' => [[
            'sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());
        if ($includeEditableDrafts) {
            $this->drafts[$delivery::class] = $deliveries->create($this->admin, ['sales_order_id' => $order->id, 'items' => [[
                'sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());
        }
        $delivery = app(SalesDeliveryPostingService::class)->post($this->admin, $delivery, $deliveries->revision($delivery));
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($this->admin, ['sales_order_id' => $order->id, 'invoice_date' => now()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());
        $invoice = $invoices->post($this->admin, $invoice, $invoices->revision($invoice));
        $this->records[SalesInvoice::class] = $invoice;
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->admin, ['sales_order_id' => $order->id, 'return_date' => now()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '1', 'disposition' => 'restock']]], (string) Str::uuid7());
        if ($includeEditableDrafts) {
            $this->drafts[$return::class] = $returns->create($this->admin, ['sales_order_id' => $order->id, 'return_date' => now()->toDateString(),
                'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '1', 'disposition' => 'restock']]], (string) Str::uuid7());
        }
        $return = app(SalesReturnApprovalService::class)->approve($this->reader, $return, $returns->revision($return));
        $return = app(SalesReturnPostingService::class)->post($this->admin, $return, $returns->revision($return));
        $credits = app(SalesCreditNoteService::class);
        $credit = $credits->create($this->admin, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
            'credit_date' => now()->toDateString(), 'reason' => 'Benchmark return', 'items' => $return->postedAllocations()
                ->where('sales_invoice_id', $invoice->id)->get()->map(fn ($row): array => [
                    'sales_return_posted_allocation_id' => $row->id, 'quantity' => '1'])->all()], (string) Str::uuid7());
        if ($includeEditableDrafts) {
            $this->drafts[$credit::class] = $credits->create($this->admin, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
                'credit_date' => now()->toDateString(), 'reason' => 'Benchmark return', 'items' => $return->postedAllocations()
                    ->where('sales_invoice_id', $invoice->id)->get()->map(fn ($row): array => [
                        'sales_return_posted_allocation_id' => $row->id, 'quantity' => '1'])->all()], (string) Str::uuid7());
        }
        $credit = $credits->post($this->admin, $credit, $credits->revision($credit));
        foreach ([$delivery, $return, $credit] as $record) {
            $this->rememberRecord($record);
        }
    }

    /** @return array<string, mixed> */
    private function measure(string $label, string $mode, Closure $operation): array
    {
        $this->queries = [];
        $this->sources = [];
        $this->cacheOperations = [];
        memory_reset_peak_usage();
        $memory = memory_get_usage(true);
        $start = hrtime(true);
        $this->recording = true;
        try {
            $value = $operation();
        } finally {
            $this->recording = false;
        }
        $elapsed = (hrtime(true) - $start) / 1e6;
        $fingerprints = [];
        foreach ($this->queries as $query) {
            $key = $query['sql'].'|'.$query['binding_hash'];
            $fingerprints[$key] ??= $query + ['executions' => 0, 'total_ms' => 0];
            $fingerprints[$key]['executions']++;
            $fingerprints[$key]['total_ms'] += $query['ms'];
        }
        $summary = ['label' => $label, 'mode' => $mode, 'queries' => count($this->queries),
            'unique_sql' => count(array_unique(array_column($this->queries, 'sql'))), 'unique_fingerprints' => count($fingerprints),
            'repeated' => count($this->queries) - count($fingerprints), 'sql_ms' => array_sum(array_column($this->queries, 'ms')),
            'wall_ms' => $elapsed, 'status' => $value instanceof TestResponse ? $value->getStatusCode() : null,
            'database_transaction_level' => DB::connection()->transactionLevel(),
            'peak_extra_bytes' => memory_get_peak_usage(true) - $memory,
            'http_requests' => in_array($mode, ['get', 'livewire'], true) ? 1 : 0, 'livewire_requests' => $mode === 'livewire' ? 1 : 0];
        usort($fingerprints, fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
        $authorizationQueries = count(array_filter($this->queries, fn (array $query): bool => count(array_filter(
            $query['source'],
            fn (string $source): bool => preg_match('~app/Modules/(?:AccessManagement/Services|[^/]+/(?:Policies|Services/[^/]*Access\\.php))~', $source) === 1,
        )) > 0));
        $cacheTable = '`'.config('cache.stores.database.table', 'cache').'`';
        $readModelCacheQueries = count(array_filter($this->queries, fn (array $query): bool => str_contains($query['sql'], $cacheTable)
            && count(array_filter($query['source'], fn (string $source): bool => str_contains($source, 'app/Filament/ReadModelCache.php'))) > 0));
        file_put_contents($this->profileDirectory.'/'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $label).'.json',
            json_encode($summary + [
                'debugbar_enabled' => app()->bound('debugbar') && app('debugbar')->isEnabled(),
                'read_cache_enabled' => (bool) config('read_cache.enabled'),
                'read_cache_store' => config('read_cache.store'),
                'cache_operations' => $this->cacheOperations,
                'authorization_queries' => $authorizationQueries,
                'read_model_cache_queries' => $readModelCacheQueries,
                'peak_bytes' => memory_get_peak_usage(true),
                'statements' => array_values($fingerprints),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDOUT, 'PROFILE '.json_encode($summary).PHP_EOL);
        if ($value instanceof TestResponse) {
            $this->assertSame(200, $value->getStatusCode(), $label.' must reach the authorized page.');
        }

        return ['summary' => $summary, 'value' => $value];
    }

    private function prepareRequest(): void
    {
        Livewire::flushState();
        $this->flushHeaders();
        $this->actingAs($this->profileActor ?? $this->reader);
        setPermissionsTeamId(null);
        $this->withSession([LocationContext::SESSION_KEY => $this->branch->id, 'locale' => 'en']);
    }

    private function snapshot(TestResponse $response): ?string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $candidate) {
            $snapshot = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            if (str_starts_with(json_decode($snapshot, true)['memo']['name'] ?? '', 'App\\')) {
                return $snapshot;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $updates
     * @param  list<array{path: string, method: string, params: array}>  $calls
     */
    private function update(string $snapshot, array $updates = [], array $calls = []): TestResponse
    {
        $endpoint = collect(Route::getRoutes())->first(fn ($route): bool => str_ends_with($route->getName() ?? '', 'livewire.update'));
        $this->prepareRequest();

        return $this->withHeader('X-Livewire', 'true')->postJson('/'.$endpoint->uri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates,
            'calls' => $calls === [] ? [['path' => '', 'method' => '$refresh', 'params' => []]] : $calls,
        ]]]);
    }

    public function test_document_lists_preserve_authorized_links_and_refresh_query_budgets(): void
    {
        $this->fixtures(1, true);
        $targets = ['PurchaseOrderResource', 'PurchaseReceiptResource', 'VendorBillResource',
            'SupplierResource', 'SalesDeliveryResource', 'SalesReturnResource'];

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! in_array(class_basename($resource), $targets, true)) {
                continue;
            }
            $record = $this->records[$resource::getModel()];
            $this->prepareRequest();
            $result = $this->measure('document-list-'.class_basename($resource), 'get',
                fn (): TestResponse => $this->get($resource::getUrl()));
            $result['value']->assertSee($resource::getUrl('view', ['record' => $record]), false);
            $snapshot = $this->snapshot($result['value']);
            $this->assertNotNull($snapshot);
            $refresh = $this->measure('document-refresh-'.class_basename($resource), 'livewire',
                fn (): TestResponse => $this->update($snapshot));
            $this->assertStringContainsString($resource::getUrl('view', ['record' => $record]),
                $refresh['value']->json('components.0.effects.html'));
            $this->assertLessThanOrEqual(55, $result['summary']['queries']);
            $this->assertLessThanOrEqual(55, $refresh['summary']['queries']);
        }

        $cases = [
            [fn (): Action => SalesOrderActions::make()[1], $this->records[SalesOrder::class], 'cancel'],
            [fn (): Action => SalesDeliveryActions::cancel(), $this->drafts[SalesDelivery::class], 'cancel'],
            [fn (): Action => SalesInvoiceActions::download('en'), $this->records[SalesInvoice::class], 'download'],
            [fn (): Action => SalesInvoiceActions::cancel(), $this->drafts[SalesInvoice::class], 'cancel'],
            [fn (): Action => SalesCreditNoteActions::download('en'), $this->records[SalesCreditNote::class], 'download'],
            [fn (): Action => SalesReturnActions::cancel(), $this->drafts[SalesReturn::class], 'cancel'],
            [fn (): Action => VendorBillActions::download('en'), $this->records[VendorBill::class], 'download'],
        ];
        setPermissionsTeamId($this->branch->id);
        $policyCalls = 0;
        $probeAbility = null;
        $probeRecord = null;
        Gate::after(function (User $actor, string $ability, ?bool $result, array $arguments) use (&$policyCalls, &$probeAbility, &$probeRecord): void {
            if ($ability === $probeAbility && ($arguments[0] ?? null) === $probeRecord) {
                $policyCalls++;
            }
        });
        foreach ($cases as [$makeAction, $record, $ability]) {
            $this->assertTrue(Gate::allows($ability, $record), $record::class.' '.$ability);
            $probeAbility = $ability;
            $probeRecord = $record;
            $policyCalls = 0;
            $this->assertFalse($makeAction()->record($record)->isHidden());
            $this->assertSame(1, $policyCalls, 'An action must not authorize its own visibility twice.');
        }

        Module::whereIn('code', ['sales', 'purchasing'])->update(['is_active' => false]);
        foreach ($cases as [$makeAction, $record]) {
            $this->assertTrue($makeAction()->record($record)->isHidden());
        }
        $this->prepareRequest();
        $this->get('/admin/purchase-orders')->assertForbidden();
    }

    public function test_every_sales_and_purchasing_route_has_authenticated_query_measurements(): void
    {
        $this->fixtures(1, true);
        $targets = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! preg_match('/Modules\\\\(Sales|Purchasing)\\\\/', $resource)) {
                continue;
            }
            foreach ($resource::getPages() as $name => $registration) {
                $record = ($name === 'edit' ? $this->drafts : $this->records)[$resource::getModel()] ?? null;
                if ($name === 'edit' && $resource::getModel() === Supplier::class) {
                    $record = $this->records[Supplier::class];
                }
                if (in_array($name, ['view', 'edit', 'finance'], true)) {
                    $this->assertNotNull($record, $resource.' '.$name.' needs an explicit fixture.');
                }
                $targets[$registration->getPage()] = $resource::getUrl($name, $record === null ? [] : ['record' => $record]);
            }
        }
        foreach (Filament::getPanel('admin')->getPages() as $page) {
            if (preg_match('/Modules\\\\(Sales|Purchasing)\\\\/', $page)) {
                $targets[$page] = $page::getUrl();
            }
        }
        $routes = collect(Route::getRoutes())->map->getActionName()->filter(fn (string $action): bool => (bool) preg_match('/^App\\\\Modules\\\\(Sales|Purchasing)\\\\.*Filament\\\\/', $action))->unique();
        $this->assertSame([], $routes->diff(array_keys($targets))->values()->all(), 'Every registered module page must be measured.');
        foreach (['restricted' => $this->reader, 'admin' => $this->admin] as $actorType => $actor) {
            $this->flushSession();
            $this->profileActor = $actor;
            foreach ($targets as $page => $url) {
                $this->prepareRequest();
                $label = 'module-'.($actorType === 'admin' ? 'admin-' : '').class_basename($page);
                $result = $this->measure($label.'-get', 'get', fn (): TestResponse => $this->get($url));
                $snapshot = $this->snapshot($result['value']);
                $this->assertNotNull($snapshot, $page.' must provide a Livewire snapshot.');
                $this->measure($label.'-refresh', 'livewire', fn (): TestResponse => $this->update($snapshot));
                $data = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['data'];
                if (array_key_exists('tableSearch', $data)) {
                    $this->measure($label.'-search', 'livewire', fn (): TestResponse => $this->update($snapshot, ['tableSearch' => 'Benchmark']));
                    $this->measure($label.'-pagination', 'livewire', fn (): TestResponse => $this->update($snapshot, [], [['path' => '', 'method' => 'gotoPage', 'params' => [2, 'page']]]));
                    $this->measure($label.'-page-size', 'livewire', fn (): TestResponse => $this->update($snapshot, ['tableRecordsPerPage' => 100]));
                    $column = match (class_basename($page)) {
                        'ListSuppliers' => 'name', 'PurchasePriceHistory' => 'quantity',
                        'ListPurchaseOrders' => 'order_number', 'ListSalesOrders' => 'order_number',
                        'ListSalesInvoices' => 'invoice_date', 'ListSalesCreditNotes' => 'credit_date',
                        'ListSalesReturns' => 'return_date', 'ListVendorBills' => 'bill_date',
                        'ListVendorPayments', 'ListSupplierAdvances' => 'payment_date',
                        default => 'created_at',
                    };
                    $this->measure($label.'-sort', 'livewire', fn (): TestResponse => $this->update($snapshot, [], [['path' => '', 'method' => 'sortTable', 'params' => [$column, 'asc']]]));
                    $filters = $data['tableDeferredFilters'][0] ?? [];
                    foreach (array_keys($filters) as $filterName) {
                        $state = match ($filterName) {
                            'status' => ['value' => 'draft'],
                            'location_id' => ['value' => $this->branch->id],
                            'supplier', 'supplier_id' => ['value' => $this->records[Supplier::class]->id],
                            'purchase_order_id' => ['value' => $this->records[PurchaseOrder::class]->id],
                            'variant_id' => ['value' => $this->records[ProductVariant::class]->id],
                            'is_active' => ['value' => true],
                            'unlinked' => ['isActive' => true],
                            'order_dates', 'dates', 'return_date', 'invoice_date' => ['from' => today()->subMonth()->toDateString(), 'until' => today()->toDateString()],
                            default => throw new \LogicException('A discovered filter needs an explicit measurement: '.$page.' '.$filterName),
                        };
                        $updates = [];
                        foreach ($state as $field => $value) {
                            $updates['tableDeferredFilters.'.$filterName.'.'.$field] = $value;
                        }
                        $this->measure($label.'-filter-'.$filterName, 'livewire', fn (): TestResponse => $this->update($snapshot,
                            $updates, [['path' => '', 'method' => 'applyTableFilters', 'params' => []]]));
                    }
                }
                if (class_basename($page) === 'Profitability') {
                    foreach (['product', 'variant', 'location', 'order'] as $group) {
                        $this->measure($label.'-report-filter-'.$group, 'livewire', fn (): TestResponse => $this->update($snapshot,
                            ['group' => $group, 'locationId' => $this->branch->id], [['path' => '', 'method' => 'applyFilters', 'params' => []]]));
                    }
                    $this->measure($label.'-report-pagination', 'livewire', fn (): TestResponse => $this->update($snapshot, [],
                        [['path' => '', 'method' => 'gotoPage', 'params' => [2, 'page']]]));
                }
                if (class_basename($page) === 'SupplierFinance') {
                    $this->measure($label.'-finance-filter', 'livewire', fn (): TestResponse => $this->update($snapshot,
                        ['locationId' => (string) $this->branch->id, 'from' => today()->subMonth()->toDateString(), 'cutoff' => today()->toDateString()]));
                    $this->measure($label.'-statement-pagination', 'livewire', fn (): TestResponse => $this->update($snapshot, [],
                        [['path' => '', 'method' => 'gotoPage', 'params' => [2, 'statementPage']]]));
                }
                if (str_starts_with(class_basename($page), 'Edit') && isset($data['data'])) {
                    $form = $data['data'][0];
                    $items = $form['items'][0] ?? [];
                    if ($items !== []) {
                        $key = array_key_first($items);
                        $this->measure($label.'-quantity-update', 'livewire', fn (): TestResponse => $this->update($snapshot, ['data.items.'.$key.'.quantity' => '0.5']));
                    }
                }
            }
        }
        $this->profileActor = null;
        fwrite(STDOUT, 'MODULE_PAGES '.count($targets).PHP_EOL);
    }

    public function test_discovered_module_modals_have_authenticated_query_measurements(): void
    {
        $this->fixtures(1, true);
        foreach (['restricted' => $this->reader, 'admin' => $this->admin] as $actorType => $actor) {
            $this->flushSession();
            $this->profileActor = $actor;
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                if (! preg_match('/Modules\\\\(Sales|Purchasing)\\\\/', $resource)) {
                    continue;
                }
                foreach ($resource::getPages() as $name => $registration) {
                    if (! in_array($name, ['index', 'view'], true)) {
                        continue;
                    }
                    $records = collect([$this->drafts[$resource::getModel()] ?? null, $this->records[$resource::getModel()]])
                        ->filter()->unique(fn (Model $record): string => $record::class.':'.$record->getKey());
                    if ($name === 'index') {
                        $records = $records->take(1);
                    }
                    foreach ($records as $record) {
                        $parameters = $name === 'view' ? ['record' => $record->getAttribute($resource::getRecordRouteKeyName() ?? $record->getRouteKeyName())] : [];
                        $page = $registration->getPage();
                        $this->prepareRequest();
                        setPermissionsTeamId($this->branch->id);
                        $probe = Livewire::test($page, $parameters)->assertSuccessful();
                        $instance = $probe->instance();
                        $candidates = [];
                        foreach ($instance->getCachedHeaderActions() as $action) {
                            if ($action instanceof Action) {
                                $candidates[] = [$action, []];
                            }
                        }
                        if ($name === 'index') {
                            foreach ($instance->getTableRecords() as $row) {
                                foreach ($instance->getTable()->getFlatActions() as $action) {
                                    $candidates[] = [(clone $action)->record($row), ['table' => true, 'recordKey' => $row->getKey()]];
                                }
                            }
                        }
                        $modals = [];
                        foreach ($candidates as [$action, $context]) {
                            if (! $action->isVisible() || $action->isDisabled() || filled($action->getUrl())) {
                                continue;
                            }
                            if ($action->isConfirmationRequired() || $action->hasModal() === true || $action->shouldOpenModal()) {
                                $modals[] = [$action->getName(), $context];
                            }
                        }
                        if ($modals === []) {
                            continue;
                        }
                        $this->prepareRequest();
                        $response = $this->get($resource::getUrl($name, $parameters))->assertOk();
                        $snapshot = $this->snapshot($response);
                        $this->assertNotNull($snapshot);
                        foreach ($modals as [$actionName, $context]) {
                            $label = 'modal-'.$actorType.'-'.class_basename($page).'-'.$actionName.'-'.($context['recordKey'] ?? 'header-'.$record->getKey());
                            $result = $this->measure($label, 'livewire', fn (): TestResponse => $this->update($snapshot, [], [[
                                'path' => '', 'method' => 'mountAction', 'params' => [$actionName, [], $context],
                            ]]));
                            $data = json_decode($result['value']->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
                            $this->assertNotEmpty($data['mountedActions'][0], $label.' must open a real modal.');
                        }
                    }
                }
            }
        }
        $this->profileActor = null;
    }

    public function test_nested_line_detail_modals_are_discovered_and_measured(): void
    {
        $this->fixtures(1);
        $measured = 0;
        foreach (['restricted' => $this->reader, 'admin' => $this->admin] as $actorType => $actor) {
            $this->flushSession();
            $this->profileActor = $actor;
            foreach (Filament::getPanel('admin')->getResources() as $resource) {
                if (! preg_match('/Modules\\\\(Sales|Purchasing)\\\\/', $resource)) {
                    continue;
                }
                $record = $this->records[$resource::getModel()];
                foreach (array_intersect_key($resource::getPages(), array_flip(['view', 'history'])) as $name => $registration) {
                    $parameters = $name === 'view' ? ['record' => $record->getAttribute($resource::getRecordRouteKeyName() ?? $record->getRouteKeyName())] : [];
                    $page = $registration->getPage();
                    $this->prepareRequest();
                    setPermissionsTeamId($this->branch->id);
                    $probe = Livewire::test($page, $parameters)->assertSuccessful();
                    $instance = $probe->instance();
                    $contexts = [];
                    foreach ($instance->getCachedSchemas() as $schema) {
                        foreach ($schema->getFlatComponents() as $component) {
                            if ($component instanceof Action && $component->getName() === 'lineDetails') {
                                $contexts[] = ['schemaComponent' => $component->getSchemaComponent()->getKey()];
                            }
                        }
                    }
                    if ($name === 'history') {
                        foreach ($instance->getTableRecords() as $row) {
                            $contexts[] = ['table' => true, 'recordKey' => $row->getKey()];
                        }
                    }
                    if ($contexts === []) {
                        continue;
                    }
                    $this->prepareRequest();
                    $response = $this->get($resource::getUrl($name, $parameters))->assertOk();
                    $snapshot = $this->snapshot($response);
                    $this->assertNotNull($snapshot);
                    foreach ($contexts as $index => $context) {
                        $label = 'line-modal-'.$actorType.'-'.class_basename($page).'-'.$index;
                        $result = $this->measure($label, 'livewire', fn (): TestResponse => $this->update($snapshot, [], [[
                            'path' => '', 'method' => 'mountAction', 'params' => ['lineDetails', [], $context],
                        ]]));
                        $data = json_decode($result['value']->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
                        $this->assertNotEmpty($data['mountedActions'][0], $label.' must render the selected line details.');
                        $measured++;
                    }
                }
            }
        }
        $this->profileActor = null;
        $this->assertGreaterThanOrEqual(16, $measured);
        fwrite(STDOUT, 'MODULE_LINE_MODALS '.$measured.PHP_EOL);
    }

    public function test_profile_major_pages_and_separate_livewire_requests(): void
    {
        $this->fixtures(20);
        $targets = [];
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (! preg_match('/Modules\\\\(Sales|Purchasing|Products|Inventory|Treasury|Payments)\\\\/', $resource)) {
                continue;
            }
            foreach ($resource::getPages() as $name => $registration) {
                if (! in_array($name, ['index', 'create', 'view', 'edit'], true)) {
                    continue;
                }
                $record = $this->records[$resource::getModel()] ?? null;
                if ($name === 'edit') {
                    $record = $this->drafts[$resource::getModel()] ?? $record;
                }
                if (in_array($name, ['view', 'edit'], true) && $record === null) {
                    continue;
                }
                if ($name === 'edit' && isset($record->status) && $record->status !== 'draft' && ! in_array($record::class, [Product::class], true)) {
                    continue;
                }
                $this->prepareRequest();
                setPermissionsTeamId($this->branch->id);
                if ($name === 'create' && ! $resource::canCreate()) {
                    continue;
                }
                $path = parse_url($resource::getUrl($name, $record === null ? [] : ['record' => $record]), PHP_URL_PATH);
                $targets[class_basename($resource).'-'.$name] = [$path, $name === 'index'];
            }
        }
        foreach (Filament::getPanel('admin')->getPages() as $page) {
            if (str_contains($page, 'Modules\\') && in_array(class_basename($page), ['Profitability', 'InventoryOverview', 'TreasuryDashboard', 'TreasuryHistory', 'CustomerPaymentHistory'], true)) {
                $targets[class_basename($page)] = [parse_url($page::getUrl(), PHP_URL_PATH), true];
            }
        }
        $supplierResource = 'App\\Modules\\Purchasing\\Filament\\Resources\\Suppliers\\SupplierResource';
        if (isset($supplierResource::getPages()['finance'])) {
            $targets['SupplierFinance'] = [parse_url($supplierResource::getUrl('finance', ['record' => $this->records[Supplier::class]]), PHP_URL_PATH), true];
        }
        foreach ($targets as $label => [$path, $refresh]) {
            $this->prepareRequest();
            $result = $this->measure($label.'-get', 'get', fn (): TestResponse => $this->get($path));
            if ($refresh && ($snapshot = $this->snapshot($result['value'])) !== null) {
                $this->measure($label.'-refresh', 'livewire', fn (): TestResponse => $this->update($snapshot));
            }
        }
        setPermissionsTeamId($this->branch->id);
        RequestAuthorizationFacts::begin(request());
        try {
            foreach (array_slice(ProductVariant::query()->orderBy('id')->get()->all(), 0, 2) as $variant) {
                $this->measure('valuation-'.$variant->product->category->inventoryValuationSetting->costing_method, 'service',
                    fn (): array => app(VariantInventoryValuation::class)->read($this->reader, $variant, $this->branch->id));
            }
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        $this->assertGreaterThan(30, count($targets));
    }

    public function test_profile_p1_01_representative_pages_in_three_runs(): void
    {
        config([
            'read_cache.enabled' => true,
            'read_cache.store' => 'array',
            'read_cache.prefix' => 'erp:p1-01:'.Str::uuid(),
            'cache.stores.array.events' => true,
        ]);
        Cache::store('array')->setEventDispatcher(Event::getFacadeRoot());
        $this->fixtures(1, true);
        $this->assertSame('array', config('read_cache.store'), 'P1-01 must not use a shared cache store.');
        $connection = DB::connection();
        $this->assertGreaterThan(0, $connection->transactionLevel());
        $connection->commit();
        $this->assertSame(0, $connection->transactionLevel());

        $resourceUrl = static function (string $suffix): string {
            $resource = collect(Filament::getPanel('admin')->getResources())
                ->first(fn (string $class): bool => str_ends_with($class, '\\'.$suffix));

            if (! is_string($resource)) {
                throw new \RuntimeException('Unable to find Filament resource '.$suffix.'.');
            }

            return $resource::getUrl();
        };
        $pageUrl = static function (string $suffix): string {
            $page = collect(Filament::getPanel('admin')->getPages())
                ->first(fn (string $class): bool => str_ends_with($class, '\\'.$suffix));

            if (! is_string($page)) {
                throw new \RuntimeException('Unable to find Filament page '.$suffix.'.');
            }

            return $page::getUrl();
        };
        $targets = [
            'FilamentShell' => parse_url(Filament::getPanel('admin')->getUrl(), PHP_URL_PATH),
            'Products' => parse_url($resourceUrl('ProductResource'), PHP_URL_PATH),
            'Purchasing' => parse_url($resourceUrl('PurchaseOrderResource'), PHP_URL_PATH),
            'Sales' => parse_url($resourceUrl('SalesOrderResource'), PHP_URL_PATH),
            'Inventory' => parse_url($pageUrl('InventoryOverview'), PHP_URL_PATH),
            'Payments' => parse_url($resourceUrl('CustomerPaymentResource'), PHP_URL_PATH),
            'Treasury' => parse_url($resourceUrl('TreasuryAccountResource'), PHP_URL_PATH),
            'POS' => parse_url($pageUrl('PosTerminal'), PHP_URL_PATH),
            'AccessManagement' => parse_url($resourceUrl('ModuleResource'), PHP_URL_PATH),
        ];

        foreach ($targets as $path) {
            $this->prepareRequest();
            $this->get($path)->assertOk();
        }
        Cache::store('array')->flush();

        foreach ($targets as $label => $path) {
            $this->assertIsString($path, $label.' must resolve to a local panel path.');

            for ($run = 1; $run <= 3; $run++) {
                Cache::store('array')->flush();
                $this->prepareRequest();
                $initial = $this->measure('p1-01-'.$label.'-get-'.$run, 'get',
                    fn (): TestResponse => $this->get($path));
                $snapshot = $this->snapshot($initial['value']);

                if ($snapshot !== null) {
                    $this->measure('p1-01-'.$label.'-livewire-'.$run, 'livewire',
                        fn (): TestResponse => $this->update($snapshot));
                }
            }
        }
    }

    public function test_account_render_query_growth_and_repeated_request_benchmarks(): void
    {
        $resource = TreasuryAccountResource::class;
        $foreign = Location::where('name', 'Benchmark Branch 2')->firstOrFail();
        $hidden = TreasuryAccount::factory()->create(['location_id' => $foreign->id, 'created_by' => $this->admin->id, 'name' => 'Foreign benchmark account']);
        $created = 0;
        $counts = [];
        foreach ([1, 20, 100] as $count) {
            while ($created < $count) {
                $last = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
                $created++;
            }
            $this->prepareRequest();
            $path = parse_url($resource::getUrl(), PHP_URL_PATH);
            for ($run = 1; $run <= 3; $run++) {
                $this->prepareRequest();
                $initial = $this->measure('accounts-'.$count.'-get-'.$run, 'get', fn (): TestResponse => $this->get($path));
                $snapshot = $this->snapshot($initial['value']);
                $this->assertNotNull($snapshot);
                $update = $this->measure('accounts-'.$count.'-all-'.$run, 'livewire',
                    fn (): TestResponse => $this->update($snapshot, ['tableRecordsPerPage' => 100]));
                $html = $update['value']->json('components.0.effects.html');
                $this->assertStringContainsString($last->code, $html);
                $this->assertStringNotContainsString($hidden->name, $html);
                $counts[$count] = $update['summary']['queries'];
            }
        }
        $this->assertLessThanOrEqual($counts[20] + 5, $counts[100], 'Repeated access facts must not grow with rendered account rows.');
    }

    private function assertAccessDenied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the current authorization boundary to deny access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_shared_facts_respect_permission_membership_location_team_and_transaction_changes(): void
    {
        $payments = app(PaymentsAccess::class);
        $treasury = app(TreasuryAccess::class);
        $vendors = app(VendorPaymentAccess::class);
        $view = PaymentsPermissions::VIEW;
        $treasuryView = TreasuryPermissions::VIEW;
        $vendorView = PurchasingPermissions::PAYMENTS_VIEW;
        $foreign = Location::where('name', 'Benchmark Branch 2')->firstOrFail();
        RequestAuthorizationFacts::begin(request());
        try {
            $payments->authorize($this->reader, $view, $this->branch->id);
            $treasury->authorize($this->reader, $treasuryView, $this->branch->id);
            $vendors->authorize($this->reader, $vendorView, $this->branch->id);
            $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $foreign->id));
            $this->assertAccessDenied(fn () => $treasury->authorize($this->reader, $treasuryView, null));
            setPermissionsTeamId($foreign->id);
            $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $foreign->id));
            $this->reader->locations()->attach($foreign);
            $this->reader->givePermissionTo($view);
            $payments->authorize($this->reader, $view, $foreign->id);
            $this->assertAccessDenied(fn () => $treasury->authorize($this->reader, $treasuryView, $foreign->id));
            setPermissionsTeamId($this->branch->id);
            $treasury->authorize($this->reader, $treasuryView, $this->branch->id);
            setPermissionsTeamId($foreign->id);
            $this->assertAccessDenied(fn () => $treasury->authorize($this->reader, $treasuryView, $foreign->id));
            setPermissionsTeamId($this->branch->id);
            $treasury->authorize($this->reader, $treasuryView, $this->branch->id);
            $this->reader->revokePermissionTo($view);
            $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $this->branch->id));
            $this->reader->givePermissionTo($view);
            $payments->authorize($this->reader, $view, $this->branch->id);
            DB::table('locations')->where('id', $this->branch->id)->update(['is_active' => false]);
            $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $this->branch->id));
            $this->assertAccessDenied(fn () => $treasury->authorize($this->reader, $treasuryView, $this->branch->id));
            $this->assertAccessDenied(fn () => $vendors->authorize($this->reader, $vendorView, $this->branch->id));
            DB::table('locations')->where('id', $this->branch->id)->update(['is_active' => true]);
            $this->reader->locations()->detach($this->branch);
            $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $this->branch->id));
            $this->reader->locations()->attach($this->branch);
            $payments->authorize($this->reader, $view, $this->branch->id);
            DB::beginTransaction();
            try {
                $payments->authorize($this->reader, $view, $this->branch->id);
                $this->reader->revokePermissionTo($view);
                $this->assertAccessDenied(fn () => $payments->authorize($this->reader, $view, $this->branch->id));
            } finally {
                DB::rollBack();
            }
            $payments->authorize($this->reader, $view, $this->branch->id);
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        $stale = $this->reader->fresh()->load(['roles', 'permissions']);
        $this->reader->revokePermissionTo($view);
        RequestAuthorizationFacts::begin(request());
        try {
            $this->assertAccessDenied(fn () => $payments->authorize($stale, $view, $this->branch->id));
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_profile_warm_requests_table_actions_selectors_and_transactional_posting(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['name' => 'Benchmark action sneaker', 'category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '300', 'quantity_available' => '300', 'quantity_reserved' => '0']);
        for ($index = 0; $index < 30; $index++) {
            $order = SalesOrder::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id,
                'customer_name' => 'Benchmark action '.$index, 'subtotal' => '50', 'total_amount' => '50']);
            SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'variant_id' => $variant->id]);
            Supplier::factory()->create(['name' => 'Benchmark supplier '.$index]);
        }
        $targets = [
            'warm-sales-orders' => '/admin/sales-orders',
            'warm-suppliers' => '/admin/suppliers',
            'warm-products' => '/admin/products',
            'warm-treasury' => '/admin/treasury',
        ];
        foreach ($targets as $label => $path) {
            for ($run = 1; $run <= 3; $run++) {
                $this->prepareRequest();
                $result = $this->measure($label.'-get-'.$run, 'get', fn (): TestResponse => $this->get($path));
                $snapshot = $this->snapshot($result['value']);
                $this->assertNotNull($snapshot);
                $this->measure($label.'-refresh-'.$run, 'livewire', fn (): TestResponse => $this->update($snapshot));
                if ($label === 'warm-sales-orders' && $run === 1) {
                    $this->measure('sales-orders-search', 'livewire', fn (): TestResponse => $this->update($snapshot, ['tableSearch' => 'Benchmark action 1']));
                    $this->measure('sales-orders-sort', 'livewire', fn (): TestResponse => $this->update($snapshot, [], [['path' => '', 'method' => 'sortTable', 'params' => ['order_number']]]));
                    $this->measure('sales-orders-pagination', 'livewire', fn (): TestResponse => $this->update($snapshot, [], [['path' => '', 'method' => 'gotoPage', 'params' => [2, 'page']]]));
                    $this->measure('sales-orders-filter', 'livewire', fn (): TestResponse => $this->update($snapshot,
                        ['tableDeferredFilters.status.value' => 'draft'], [['path' => '', 'method' => 'applyTableFilters', 'params' => []]]));
                }
            }
        }
        $this->prepareRequest();
        setPermissionsTeamId($this->branch->id);
        RequestAuthorizationFacts::begin(request());
        try {
            $options = $this->measure('product-search-selector', 'service', fn (): array => app(SalesProductSelection::class)
                ->productOptions($this->reader, $this->branch->id, 'Benchmark'));
            $this->assertSame([$product->id], array_keys($options['value']));
            $this->assertLessThanOrEqual(50, count($options['value']));
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        $source = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $destination = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->admin->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->admin, $treasury->createDraft($this->admin, ['type' => 'opening_balance', 'account_id' => $source->id,
            'amount' => '1000', 'effective_date' => now()->toDateString(), 'reason' => 'Benchmark transfer funds'], (string) Str::uuid7()));
        for ($run = 1; $run <= 3; $run++) {
            $draft = $treasury->createDraft($this->admin, ['type' => 'transfer', 'account_id' => $source->id, 'destination_account_id' => $destination->id,
                'amount' => '25', 'effective_date' => now()->toDateString(), 'reason' => 'Benchmark internal transfer'], (string) Str::uuid7());
            RequestAuthorizationFacts::begin(request());
            try {
                $posted = $this->measure('treasury-post-'.$run, 'service', fn () => $treasury->post($this->reader, $draft));
                $replayed = $this->measure('treasury-replay-'.$run, 'service', fn () => $treasury->post($this->reader, $draft));
                $this->assertSame($posted['value']->id, $replayed['value']->id);
                $this->assertSame(2, $posted['value']->entries()->count());
            } finally {
                RequestAuthorizationFacts::end(request());
            }
        }
        $this->assertSame('925.0000', $treasury->balance($this->admin, $source));
        $this->assertSame('75.0000', $treasury->balance($this->admin, $destination));
    }

    public function test_profile_variant_scaling_authorization_call_sites_and_query_plans(): void
    {
        $product = Product::factory()->create(['name' => 'Benchmark variant family', 'status' => 'active', 'is_active' => true]);
        Route::get('/query-benchmark/variants', fn (): string => Blade::render('@livewire($component, $parameters)', [
            'component' => VariantsRelationManager::class,
            'parameters' => ['ownerRecord' => $product, 'pageClass' => ViewProduct::class],
        ]))->middleware(['web', 'auth', SetLocationPermissionContext::class]);
        $created = 0;
        foreach ([1, 20, 100] as $count) {
            while ($created < $count) {
                ProductVariant::factory()->create(['product_id' => $product->id]);
                $created++;
            }
            $this->prepareRequest();
            $product->unsetRelations();
            $expected = $product->variants()->orderBy('id')->limit(10)->get();
            $result = $this->measure('variants-'.$count, 'get', fn (): TestResponse => $this->get('/query-benchmark/variants'));
            foreach ($expected as $variant) {
                $this->assertStringContainsString($variant->sku, $result['value']->getContent());
            }
            $snapshot = $this->snapshot($result['value']);
            $this->assertNotNull($snapshot);
            $this->measure('variants-'.$count.'-refresh', 'livewire', fn (): TestResponse => $this->update($snapshot));
        }
        $this->prepareRequest();
        setPermissionsTeamId($this->branch->id);
        $helpers = [
            'products' => fn () => app(ProductsAccess::class)->authorize($this->reader, 'products.view'),
            'purchasing' => fn () => app(PurchasingAccess::class)->authorize($this->reader, PurchasingPermissions::ORDERS_VIEW, $this->branch->id),
            'inventory' => fn () => app(InventoryAccess::class)->authorize($this->reader, InventoryPermissions::BALANCES, $this->branch->id),
            'definitions' => fn () => app(DefinitionAccess::class)->authorize($this->reader, 'taxes', 'view'),
            'payments' => fn () => app(PaymentsAccess::class)->authorize($this->reader, PaymentsPermissions::VIEW, $this->branch->id),
            'treasury' => fn () => app(TreasuryAccess::class)->authorize($this->reader, TreasuryPermissions::VIEW, $this->branch->id),
            'vendors' => fn () => app(VendorPaymentAccess::class)->authorize($this->reader, PurchasingPermissions::PAYMENTS_VIEW, $this->branch->id),
        ];
        foreach ($helpers as $name => $authorize) {
            RequestAuthorizationFacts::begin(request());
            try {
                $this->measure('authorization-call-site-'.$name, 'service', function () use ($authorize): void {
                    for ($index = 0; $index < 20; $index++) {
                        $authorize();
                    }
                });
            } finally {
                RequestAuthorizationFacts::end(request());
            }
        }
        $queries = [
            'active-location' => Location::whereKey($this->branch->id)->where('is_active', true),
            'actor-membership' => $this->reader->locations()->whereKey($this->branch->id),
            'direct-permissions' => $this->reader->permissions(),
        ];
        $plans = [];
        foreach ($queries as $name => $query) {
            $plans[$name] = ['sql' => $query->toSql(), 'plan' => DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())];
        }
        file_put_contents($this->profileDirectory.'/query-plans.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertCount(3, $plans);
    }
}
