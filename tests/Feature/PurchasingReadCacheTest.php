<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingDisplay;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\SupplierFinancialOverview;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingReadCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function beginDatabaseTransaction(): void
    {
        $manager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new \LogicException('Cache tests require a disposable local test database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['read_cache.enabled' => true, 'read_cache.store' => 'redis',
            'read_cache.prefix' => 'erp:purchasing-test:'.getmypid().':'.Str::uuid(),
            'database.redis.client' => 'predis', 'database.redis.cache.timeout' => 3,
            'database.redis.cache.read_write_timeout' => 3, 'cache.default' => 'array']);
        app()->forgetInstance(ReadModelCache::class);
        app('redis')->connection('cache')->ping();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function actor(): User
    {
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'products', 'inventory', 'inventory_costing', 'treasury', 'payments', 'taxes', 'discounts'])->update(['is_active' => true]);
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        session(['locale' => 'en']);

        return $actor;
    }

    /** @return array<string, mixed> */
    private function purchase(User $actor): array
    {
        $branch = Location::create(['name' => 'Cache purchasing branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'avco']);
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true, 'status' => 'active']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'order_date' => today()->toDateString(), 'items' => [['variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '10']]], (string) Str::uuid7());
        $order = $orders->confirm($actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->update($actor, $receipt, ['items' => [['id' => $receipt->items->first()->id,
            'quantity' => '3', 'unit_cost' => '10']]], $receipts->revision($receipt));

        return compact('branch', 'product', 'variant', 'supplier', 'order', 'receipt');
    }

    public function test_receipt_posting_bill_posting_allocation_and_release_refresh_display_snapshots(): void
    {
        $actor = $this->actor();
        ['branch' => $branch, 'product' => $product, 'variant' => $variant, 'supplier' => $supplier, 'order' => $order, 'receipt' => $receipt] = $this->purchase($actor);
        $display = app(PurchasingDisplay::class);
        $this->assertSame([], $display->received($actor, $order));
        $this->assertSame('0.0000', $display->receiptSummary($actor, $order)['received']);
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->post($actor, $receipt, $receipts->revision($receipt));
        $this->assertSame('3.0000', $display->receiptSummary($actor, $order)['received']);
        $this->assertSame('0.0000', $display->receiptSummary($actor, $order)['remaining']);
        $this->assertArrayHasKey($order->uuid, app(DocumentSelectors::class)->orders($actor, 'purchase'));

        $bills = app(VendorBillService::class);
        $bill = $bills->create($actor, $bills->prefill($actor, $order) + ['bill_number' => 'CACHE-'.Str::uuid()]);
        $finance = app(SupplierFinancialOverview::class);
        $this->assertSame('0.0000', $finance->reportForDisplay($actor, $supplier->id)['totals']['billed']);
        $bill = $bills->post($actor, $bill, $bills->revision($bill));
        $this->assertSame('30.0000', $finance->reportForDisplay($actor, $supplier->id)['totals']['billed']);
        $this->assertArrayNotHasKey($order->uuid, app(DocumentSelectors::class)->orders($actor, 'purchase'));
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($actor, $treasury->createDraft($actor, ['type' => 'opening_balance', 'account_id' => $cash->id,
            'amount' => '1000', 'effective_date' => today()->toDateString(), 'reason' => 'Cache regression funds'], (string) Str::uuid7()));
        $method = PaymentMethod::factory()->create();
        $payments = app(VendorPaymentService::class);
        $payment = $payments->post($actor, $payments->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => today()->toDateString(), 'amount' => '20'], (string) Str::uuid7()));
        $balances = app(SupplierFinancialBalances::class);
        $this->assertSame('30.0000', $balances->billForDisplay($actor, $bill)['outstanding']);
        $this->assertSame('20.0000', $balances->paymentForDisplay($actor, $payment)['unallocated']);
        $allocations = app(VendorPaymentAllocationService::class);
        $allocation = $allocations->allocate($actor, $payment, $bill, '15', (string) Str::uuid7());
        $this->assertSame('15.0000', $balances->billForDisplay($actor, $bill)['outstanding']);
        $this->assertSame('5.0000', $balances->paymentForDisplay($actor, $payment)['unallocated']);
        $this->assertSame('15.0000', $finance->reportForDisplay($actor, $supplier->id)['totals']['allocated']);
        foreach (['miss', 'hit'] as $phase) {
            app('livewire')->flushState();
            $this->get(VendorPaymentResource::getUrl('view', ['record' => $payment]))->assertOk()
                ->assertSee($bill->bill_number)->assertSee(VendorBillResource::getUrl('view', ['record' => $bill]), false);
        }
        $allocations->release($actor, $allocation, (string) Str::uuid7(), 'Correct cache test allocation');
        $this->assertSame('30.0000', $balances->billForDisplay($actor, $bill)['outstanding']);
        $this->assertSame('20.0000', $balances->paymentForDisplay($actor, $payment)['unallocated']);
        $this->assertSame('0.0000', $finance->reportForDisplay($actor, $supplier->id)['totals']['allocated']);
        $this->assertDatabaseHas('vendor_payment_allocations', ['id' => $allocation->id, 'released_by' => $actor->id]);
    }

    public function test_purchasing_pages_render_with_cold_and_warm_caches(): void
    {
        $actor = $this->actor();
        ['branch' => $branch, 'product' => $product, 'variant' => $variant, 'supplier' => $supplier, 'order' => $order, 'receipt' => $receipt] = $this->purchase($actor);
        $orders = app(PurchaseOrderService::class);
        $draftOrder = $orders->create($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '10']]], (string) Str::uuid7());
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->post($actor, $receipt, $receipts->revision($receipt));
        $bills = app(VendorBillService::class);
        $bill = $bills->create($actor, $bills->prefill($actor, $order) + ['bill_number' => 'PAGE-'.Str::uuid()]);
        $receivingOrder = $orders->create($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => [['variant_id' => $variant->id, 'quantity' => '1', 'unit_price' => '10']]], (string) Str::uuid7());
        $receivingOrder = $orders->confirm($actor, $receivingOrder, $orders->revision($receivingOrder));
        $draftReceipt = $receivingOrder->receipts()->firstOrFail();
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $method = PaymentMethod::factory()->create();
        $payment = app(VendorPaymentService::class)->createDraft($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id,
            'treasury_account_id' => $cash->id, 'payment_method_id' => $method->id, 'payment_date' => today()->toDateString(), 'amount' => '10'], (string) Str::uuid7());
        $targets = [
            [PurchaseOrderResource::class, ['index', 'create', 'history', 'view', 'edit'], $draftOrder],
            [PurchaseReceiptResource::class, ['index', 'view'], $receipt],
            [PurchaseReceiptResource::class, ['edit'], $draftReceipt],
            [VendorBillResource::class, ['index', 'create', 'view', 'edit'], $bill],
            [SupplierResource::class, ['index', 'create', 'view', 'edit', 'finance'], $supplier],
            [VendorPaymentResource::class, ['index', 'create', 'advances', 'view'], $payment],
        ];
        foreach ($targets as [$resource, $pages, $record]) {
            foreach ($pages as $page) {
                foreach (['miss', 'hit'] as $phase) {
                    app('livewire')->flushState();
                    $this->get($resource::getUrl($page, $record === null ? [] : ['record' => $record]))->assertOk();
                }
            }
        }
    }

    public function test_bulk_invalidation_commits_atomically_and_nested_rollback_preserves_the_cached_read(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Original supplier']);
        $query = Supplier::whereKey($supplier->id);
        $cache = app(ReadModelCache::class);
        $this->assertSame([$supplier->id => 'Original supplier'], $cache->options('suppliers', $query));
        DB::beginTransaction();
        DB::beginTransaction();
        DB::table('suppliers')->where('id', $supplier->id)->update(['name' => 'Rolled back supplier']);
        $cache->invalidateAfterCommit('suppliers');
        DB::rollBack();
        DB::commit();
        $this->assertSame([$supplier->id => 'Original supplier'], (new ReadModelCache)->options('suppliers', $query));
        DB::transaction(function () use ($supplier, $cache): void {
            DB::table('suppliers')->where('id', $supplier->id)->update(['name' => 'Committed supplier']);
            $cache->invalidateAfterCommit('suppliers');
        });
        $this->assertSame([$supplier->id => 'Committed supplier'], (new ReadModelCache)->options('suppliers', $query));
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'Committed supplier']);
    }

    public function test_losing_shared_version_rows_does_not_resurrect_an_old_payload(): void
    {
        $reads = 0;
        $read = function () use (&$reads): array {
            return ['read' => ++$reads];
        };
        $cache = app(ReadModelCache::class);
        $this->assertSame(['read' => 1], $cache->remember('purchasing', ['epoch-loss'], $read));
        $cache->invalidateAfterCommit('purchasing');
        $this->assertSame(['read' => 2], $cache->remember('purchasing', ['epoch-loss'], $read));

        DB::table('cache')->where('key', 'like', config('read_cache.prefix').':durable-version:%')->delete();

        $this->assertSame(['read' => 3], (new ReadModelCache)->remember('purchasing', ['epoch-loss'], $read));
    }

    public function test_variant_label_hits_avoid_catalog_queries_and_refresh_after_product_changes(): void
    {
        $actor = $this->actor();
        $product = Product::factory()->create(['name' => 'Original cache product', 'status' => 'active', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $queries = 0;
        $capturing = false;
        DB::listen(function (QueryExecuted $event) use (&$queries, &$capturing): void {
            if ($capturing && preg_match('/from `(product_variants|products|colors|sizes)`/', $event->sql)) {
                $queries++;
            }
        });
        $read = fn (): array => app(PurchasingDisplay::class)->variantOptions(app(PurchaseOrderService::class)->variants($actor)->whereKey($variant->id)->limit(50));
        $capturing = true;
        $cold = $read();
        $this->assertArrayHasKey($variant->id, $cold);
        $missQueries = $queries;
        $queries = 0;
        $this->assertSame($cold, $read());
        $capturing = false;
        $this->assertSame(0, $queries);
        $this->assertGreaterThan(0, $missQueries);
        $product->update(['name' => 'Changed cache product']);
        $this->assertStringContainsString('Changed cache product', $read()[$variant->id]);
        fwrite(STDOUT, 'PURCHASING_CACHE '.json_encode(['target' => 'variant-options', 'miss_catalog_queries' => $missQueries, 'hit_catalog_queries' => $queries], JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
