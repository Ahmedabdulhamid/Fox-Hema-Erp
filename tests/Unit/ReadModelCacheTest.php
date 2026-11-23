<?php

namespace Tests\Unit;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Models\Discount;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\POS\Models\PosDevice;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Treasury\Filament\Pages\TreasuryDashboard;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ReadModelCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Cache integration must exercise committed transactions, not an enclosing test transaction. */
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
            throw new \LogicException('Redis integration requires the disposable MySQL bootstrap.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['read_cache.enabled' => true, 'read_cache.store' => 'redis',
            'read_cache.prefix' => 'erp:test:'.getmypid().':'.bin2hex(random_bytes(8)),
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

    public function test_real_redis_miss_hit_and_model_create_update_delete_invalidation(): void
    {
        $reads = 0;
        $read = function () use (&$reads): array {
            $reads++;

            return Brand::query()->orderBy('id')->pluck('name', 'id')->all();
        };
        $cache = app(ReadModelCache::class);
        $first = $cache->remember('catalog', ['brands'], $read);
        $this->assertSame($first, $cache->remember('catalog', ['brands'], $read));
        $this->assertSame(1, $reads);
        $brand = Brand::factory()->create();
        $this->assertArrayHasKey($brand->id, $cache->remember('catalog', ['brands'], $read));
        $brand->update(['name' => 'Updated cache brand '.bin2hex(random_bytes(5))]);
        $this->assertSame($brand->name, $cache->remember('catalog', ['brands'], $read)[$brand->id]);
        $brand->delete();
        $this->assertArrayNotHasKey($brand->id, $cache->remember('catalog', ['brands'], $read));
        $this->assertSame(4, $reads);
    }

    public function test_supplier_tax_discount_and_variant_changes_invalidate_their_display_names(): void
    {
        foreach ([
            [Supplier::class, 'suppliers', 'name'],
            [Product::class, 'catalog', 'name'],
            [Location::class, 'access', 'name'],
            [Tax::class, 'taxes', 'name'],
            [Discount::class, 'discounts', 'name'],
            [ProductVariant::class, 'catalog', 'sku'],
        ] as [$class, $group, $field]) {
            $record = $class === Location::class
                ? Location::create(['name' => 'Cache location', 'type' => 'branch', 'is_active' => true])
                : $class::factory()->create();
            $read = fn (): array => [$field => $class::whereKey($record->getKey())->value($field)];
            $context = [$class, $record->getKey()];
            $cache = app(ReadModelCache::class);
            $this->assertSame($read(), $cache->remember($group, $context, $read));
            $changed = 'Cache-'.bin2hex(random_bytes(6));
            $record->update([$field => $changed]);
            $this->assertSame([$field => $changed], $cache->remember($group, $context, $read));
            $record->delete();
            $this->assertSame([$field => null], $cache->remember($group, $context, $read));
        }
    }

    public function test_commit_invalidates_but_rollback_preserves_the_committed_cache_and_transactions_bypass_it(): void
    {
        $brand = Brand::factory()->create();
        $original = $brand->name;
        $reads = 0;
        $read = function () use ($brand, &$reads): array {
            $reads++;

            return ['name' => Brand::whereKey($brand->id)->value('name')];
        };
        $cache = app(ReadModelCache::class);
        $this->assertSame(['name' => $original], $cache->remember('catalog', [$brand->id], $read));
        DB::beginTransaction();
        $brand->update(['name' => 'Rolled back '.bin2hex(random_bytes(4))]);
        $this->assertSame(['name' => $brand->name], $cache->remember('catalog', [$brand->id], $read));
        DB::rollBack();
        $this->assertSame(['name' => $original], $cache->remember('catalog', [$brand->id], $read));
        $this->assertSame(2, $reads);
        DB::transaction(function () use ($brand): void {
            $brand->update(['name' => 'Committed '.bin2hex(random_bytes(4))]);
            $brand->update(['name' => 'Committed again '.bin2hex(random_bytes(4))]);
        });
        $this->assertSame(['name' => $brand->name], $cache->remember('catalog', [$brand->id], $read));
        $this->assertSame(3, $reads);
    }

    public function test_pos_observer_invalidates_after_commit_and_rollback_keeps_the_committed_read_model(): void
    {
        $branch = Location::create(['name' => 'POS cache branch', 'type' => 'branch', 'is_active' => true]);
        $device = PosDevice::factory()->create(['location_id' => $branch->id, 'name' => 'Committed device']);
        $cache = app(ReadModelCache::class);
        $read = fn (): array => ['name' => PosDevice::whereKey($device->id)->value('name')];

        $this->assertSame(['name' => 'Committed device'], $cache->remember('pos', [$device->id], $read));
        DB::beginTransaction();
        $device->update(['name' => 'Rolled back device']);
        $this->assertSame(['name' => 'Rolled back device'], $cache->remember('pos', [$device->id], $read));
        DB::rollBack();
        $this->assertSame(['name' => 'Committed device'], $cache->remember('pos', [$device->id], $read));

        DB::transaction(fn () => $device->update(['name' => 'Committed POS update']));
        $this->assertSame(['name' => 'Committed POS update'], $cache->remember('pos', [$device->id], $read));
        $device->delete();
        $this->assertSame(['name' => null], $cache->remember('pos', [$device->id], $read));
    }

    public function test_cache_keys_isolate_users_branches_filters_dates_and_query_bindings(): void
    {
        $reads = 0;
        $read = function () use (&$reads): array {
            return ['read' => ++$reads];
        };
        $cache = app(ReadModelCache::class);
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->actingAs($one);
        setPermissionsTeamId(1);
        $this->assertSame(['read' => 1], $cache->remember('catalog', ['from' => '2026-10-01', 'search' => 'a'], $read));
        $this->assertSame(['read' => 1], $cache->remember('catalog', ['from' => '2026-10-01', 'search' => 'a'], $read));
        setPermissionsTeamId(2);
        $this->assertSame(['read' => 2], $cache->remember('catalog', ['from' => '2026-10-01', 'search' => 'a'], $read));
        $this->actingAs($two);
        $this->assertSame(['read' => 3], $cache->remember('catalog', ['from' => '2026-10-01', 'search' => 'a'], $read));
        $this->assertSame(['read' => 4], $cache->remember('catalog', ['from' => '2026-10-02', 'search' => 'a'], $read));
        $this->assertSame(['read' => 5], $cache->remember('catalog', ['from' => '2026-10-02', 'search' => 'b'], $read));
    }

    public function test_related_model_changes_invalidate_dependent_report_groups_only(): void
    {
        $reads = 0;
        $read = function () use (&$reads): array {
            return ['read' => ++$reads];
        };
        $cache = app(ReadModelCache::class);
        $this->assertSame(['read' => 1], $cache->remember('sales', ['report'], $read));
        $this->assertSame(['read' => 2], $cache->remember('suppliers', ['lookup'], $read));
        Brand::factory()->create();
        $this->assertSame(['read' => 3], $cache->remember('sales', ['report'], $read));
        $this->assertSame(['read' => 2], $cache->remember('suppliers', ['lookup'], $read));
    }

    public function test_real_purchase_receipt_commit_invalidates_stock_display_and_cached_stock_never_authorizes_a_sale(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'products', 'inventory', 'inventory_costing', 'sales'])->update(['is_active' => true]);
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, null);
        $branch = Location::create(['name' => 'Redis posting branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'avco']);
        $product = Product::factory()->create(['category_id' => $category->id, 'is_active' => true, 'status' => 'active', 'has_fixed_selling_price' => true, 'sale_price' => '10']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $cache = app(ReadModelCache::class);
        $read = fn (): array => ['quantity' => (string) DB::table('stock_balances')->where('variant_id', $variant->id)->where('location_id', $branch->id)->sum('quantity_available')];
        $cache->remember('inventory', ['posting', $variant->id], $read);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($actor, ['supplier_id' => $supplier->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => [['variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '2.5']]], (string) Str::uuid7());
        $this->assertSame('7.5000', $order->total_amount);
        $order = $orders->confirm($actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $receipts->update($actor, $receipt, ['items' => [['id' => $receipt->items->first()->id, 'quantity' => '3', 'unit_cost' => '2.5']]], $receipts->revision($receipt));
        $this->useUnavailableRedis();
        $receipts->post($actor, $receipt, $receipts->revision($receipt));
        config(['read_cache.store' => 'redis']);
        app()->forgetInstance(ReadModelCache::class);
        $cache = app(ReadModelCache::class);
        $this->assertSame(['quantity' => '3.0000'], $cache->remember('inventory', ['posting', $variant->id], $read));
        $cache->remember('inventory', ['forged-display'], fn (): array => ['quantity' => '9999']);
        try {
            app(SalesOrderService::class)->create($actor, ['location_id' => $branch->id,
                'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => '4', 'unit_price' => '10']]], (string) Str::uuid7());
            $this->fail('Cached display stock must not authorize an understocked sale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.quantity', $exception->errors());
        }
        $this->assertSame('3.0000', $read()['quantity']);
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_redis_failure_falls_back_and_a_failed_invalidation_is_repaired_after_recovery(): void
    {
        $brand = Brand::factory()->create();
        $read = fn (): array => ['name' => Brand::whereKey($brand->id)->value('name')];
        $cache = app(ReadModelCache::class);
        $cache->remember('catalog', [$brand->id], $read);
        $this->useUnavailableRedis();
        $brand->update(['name' => 'Changed during outage '.bin2hex(random_bytes(4))]);
        $this->assertSame(['name' => $brand->name], $cache->remember('catalog', [$brand->id], $read));
        config(['read_cache.store' => 'redis']);
        app()->forgetInstance(ReadModelCache::class);
        $this->assertSame(['name' => $brand->name], app(ReadModelCache::class)->remember('catalog', [$brand->id], $read));
        $this->assertSame($brand->name, Brand::whereKey($brand->id)->value('name'));
    }

    public function test_representative_filament_pages_cold_miss_and_hit_use_real_http_and_redis(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'products', 'treasury', 'taxes', 'discounts'])->update(['is_active' => true]);
        $admin = $this->provisionTestSuperAdmin();
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin);
        app(LocationContext::class)->setFor($admin, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $supplier = Supplier::factory()->create();
        $capturing = false;
        $queries = [];
        $redis = 0;
        DB::listen(static function (QueryExecuted $event) use (&$capturing, &$queries): void {
            if ($capturing) {
                $queries[] = ['sql' => $event->sql, 'ms' => $event->time];
            }
        });
        app('redis')->enableEvents();
        app('redis')->connection('cache')->setEventDispatcher(app('events'));
        Event::listen(CommandExecuted::class, static function () use (&$capturing, &$redis): void {
            if ($capturing) {
                $redis++;
            }
        });
        $targets = [
            'purchase-order-create' => PurchaseOrderResource::getUrl('create'),
            'product-create' => ProductResource::getUrl('create'),
            'treasury-dashboard' => TreasuryDashboard::getUrl(),
            'supplier-finance' => SupplierResource::getUrl('finance', ['record' => $supplier]),
        ];
        foreach ($targets as $target => $url) {
            $counts = [];
            $totals = [];
            foreach (['uncached' => false, 'miss' => true, 'hit' => true] as $phase => $enabled) {
                config(['read_cache.enabled' => $enabled]);
                app('livewire')->flushState();
                $queries = [];
                $redis = 0;
                $capturing = true;
                $start = hrtime(true);
                try {
                    $response = $this->get($url);
                } finally {
                    $duration = (hrtime(true) - $start) / 1000000;
                    $capturing = false;
                }
                $response->assertOk();
                $totals[$phase] = count($queries);
                $counts[$phase] = count(array_filter($queries, fn (array $query): bool => str_contains($query['sql'], 'from `suppliers`') && str_contains($query['sql'], 'limit 50')));
                fwrite(STDOUT, 'REDIS_HTTP '.json_encode(['target' => $target, 'phase' => $phase, 'queries' => count($queries), 'lookup_queries' => $counts[$phase], 'redis_commands' => $redis, 'database_ms' => array_sum(array_column($queries, 'ms')), 'request_ms' => $duration], JSON_THROW_ON_ERROR).PHP_EOL);
            }
            if ($target === 'purchase-order-create') {
                $this->assertSame(['uncached' => 1, 'miss' => 1, 'hit' => 0], $counts);
            }
            $this->assertLessThan($totals['miss'], $totals['hit'], $target.' cache hits must avoid database work.');
        }
        $branch = Location::create(['name' => 'Redis restricted branch', 'type' => 'branch', 'is_active' => true]);
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $reader->givePermissionTo(['purchasing.purchase_orders.view', 'purchasing.purchase_orders.create']);
        $this->flushSession();
        $this->actingAs($reader);
        app(LocationContext::class)->setFor($reader, $branch->id);
        app('livewire')->flushState();
        $this->get($targets['purchase-order-create'])->assertOk();
        app('livewire')->flushState();
        $this->get($targets['treasury-dashboard'])->assertForbidden();
        setPermissionsTeamId($branch->id);
        $reader->revokePermissionTo('purchasing.purchase_orders.create');
        app('livewire')->flushState();
        $this->get($targets['purchase-order-create'])->assertForbidden();
    }

    private function useUnavailableRedis(): void
    {
        $connection = config('database.redis.cache');
        $connection['url'] = null;
        $connection['host'] = '127.0.0.1';
        $connection['port'] = 1;
        $connection['timeout'] = 0.1;
        $connection['read_write_timeout'] = 0.1;
        config(['database.redis.erp_unavailable' => $connection,
            'cache.stores.erp_unavailable' => ['driver' => 'redis', 'connection' => 'erp_unavailable'],
            'read_cache.store' => 'erp_unavailable']);
        app()->forgetInstance('redis');
    }
}
