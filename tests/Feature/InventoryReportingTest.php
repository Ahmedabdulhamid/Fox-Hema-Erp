<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Reporting\Filament\Pages\InventoryReports;
use App\Modules\Reporting\Filament\Widgets\DailyInventoryChart;
use App\Modules\Reporting\Jobs\ProjectInventoryDay;
use App\Modules\Reporting\Services\InventoryProjection;
use App\Modules\Reporting\Services\InventoryProjectionRecovery;
use App\Modules\Reporting\Services\InventoryReadReport;
use App\Modules\Reporting\Services\InventoryReportingAccess;
use App\Modules\Reporting\Services\InventoryReportingInvalidation;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\StockTransfer\Services\StockTransferService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class InventoryReportingTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

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
            throw new \LogicException('Inventory reporting tests require a disposable test schema.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetInstance(DocumentNumberService::class);
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
        $this->branch = Location::create(['name' => 'Inventory report '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->configureSalesPricing($this->variant, '10', '14');
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'inventory-report-test:'.Str::uuid()]);
    }

    private function postStock(string $quantity, StockMovementType $type = StockMovementType::Purchase): StockMovement
    {
        return app(InventoryPostingService::class)->post(new StockPostingCommand($this->variant, $this->branch, $type, $quantity, (string) Str::uuid7(), notes: 'Reporting evidence test'), $this->actor);
    }

    private function project(string $date = '2026-11-01', ?int $location = null): void
    {
        $projection = app(InventoryProjection::class);
        $projection->request($location ?? $this->branch->id, $date);
        $projection->project($location ?? $this->branch->id, $date);
    }

    private function report(string $until = '2026-11-01', ?int $location = null): array
    {
        return app(InventoryReadReport::class)->read($this->actor, '2026-11-01', $until, $location ?? $this->branch->id);
    }

    private function receive(string $method = 'fifo', string $quantity = '10', string $cost = '2'): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => $cost]);
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => $cost, 'effective_at' => now()->subDay()]);
        }
        $orders = app(PurchaseOrderService::class);
        $purchase = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $this->variant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                'variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => $cost]]], (string) Str::uuid7());
        $purchase = $orders->confirm($this->actor, $purchase, $orders->revision($purchase));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $purchase->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
    }

    private function deliver(): SalesDelivery
    {
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->actor, ['location_id' => $this->branch->id, 'items' => [['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '20']]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($this->actor, ['sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '10']]], (string) Str::uuid7());

        return app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $deliveries->revision($delivery));
    }

    public function test_current_quantities_and_policies_do_not_invent_historical_reservations(): void
    {
        $this->postStock('5.1234');
        DB::table('stock_balances')->where('location_id', $this->branch->id)->update(['quantity_reserved' => '2.0001', 'quantity_available' => '3.1233']);
        DB::table('inventory_stock_policies')->insert(['location_id' => $this->branch->id, 'variant_id' => $this->variant->id, 'reorder_threshold' => '4', 'dead_stock_days' => 90, 'updated_by' => $this->actor->id]);
        $this->project();
        $report = $this->report();
        $this->assertSame('5.1234', $report['totals']['on_hand']);
        $this->assertSame('2.0001', $report['totals']['reserved']);
        $this->assertSame('3.1233', $report['totals']['available']);
        $this->assertSame(1, (int) $report['totals']['low_count']);
        $this->assertSame('5.1234', $report['closing']['totals']['quantity']);
        $this->assertNull($report['closing']['totals']['value']);
        $this->assertArrayNotHasKey('reserved', $report['closing']['totals']);
        DB::table('stock_balances')->where('location_id', $this->branch->id)->update(['quantity_reserved' => '1', 'quantity_available' => '4.1234']);
        $this->assertSame('1.0000', $this->report()['totals']['reserved']);
        $this->assertSame(0, $this->report()['freshness']['pending_days']);
    }

    public static function costingMethods(): array
    {
        return [['fifo'], ['avco'], ['standard']];
    }

    #[DataProvider('costingMethods')]
    public function test_historical_values_use_posted_cost_deltas_not_present_day_stock(string $method): void
    {
        $this->receive($method);
        $this->travel(1)->days();
        $delivery = $this->deliver();
        $this->project();
        $this->project('2026-11-02');
        $past = $this->report();
        $current = $this->report('2026-11-02');
        $this->assertSame('0.0000', $past['totals']['on_hand']);
        $this->assertSame('10.0000', $past['closing']['totals']['quantity']);
        $this->assertSame('20.0000', $past['closing']['totals']['value']);
        $this->assertSame('0.0000', $current['closing']['totals']['value']);
        $this->assertSame(0, (int) $past['closing']['totals']['missing_cost']);
        if ($method === 'fifo') {
            $movement = $delivery->items()->firstOrFail()->stock_movement_id;
            $entry = DB::table('inventory_cost_entries')->where('stock_movement_id', $movement)->value('id');
            DB::table('fifo_layer_consumptions')->where('cost_entry_id', $entry)->update(['total_cost' => '0']);
            $this->project();
            $this->assertNull($this->report()['closing']['totals']['value']);
        }
    }

    public function test_missing_opening_quantity_evidence_is_unavailable_even_with_current_balance(): void
    {
        $this->postStock('2');
        DB::table('stock_balances')->where('location_id', $this->branch->id)->update(['quantity_on_hand' => '7', 'quantity_available' => '7']);
        $this->project();
        $report = $this->report();
        $this->assertSame('7.0000', $report['totals']['on_hand']);
        $this->assertNull($report['closing']['totals']['quantity']);
        $this->assertNull($report['closing']['totals']['value']);
        $this->assertSame(1, (int) $report['closing']['totals']['missing_quantity']);
    }

    public function test_adjustments_and_compensating_reversals_remain_separate_movements(): void
    {
        $this->postStock('5.1234');
        $adjustment = $this->postStock('-1.0001', StockMovementType::Adjustment);
        $this->travel(1)->days();
        app(InventoryPostingService::class)->reverse($adjustment, (string) Str::uuid7(), $this->actor, 'Undo adjustment');
        $this->project();
        $this->project('2026-11-02');
        $this->assertSame('4.1233', $this->report()['closing']['totals']['quantity']);
        $report = $this->report('2026-11-02');
        $this->assertSame('5.1234', $report['closing']['totals']['quantity']);
        $types = collect($report['trend']['types'])->keyBy('type');
        $this->assertSame(1, (int) $types['adjustment']['reversals']);
        $this->assertSame(3, $report['history']['total']);
    }

    public function test_actual_transfers_are_scoped_to_both_locations_without_double_counting(): void
    {
        $this->receive();
        $warehouse = Location::create(['name' => 'Report warehouse '.Str::uuid(), 'type' => 'warehouse', 'is_active' => true]);
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($this->actor, ['from_location_id' => $this->branch->id, 'to_location_id' => $warehouse->id, 'items' => [['variant_id' => $this->variant->id, 'requested_quantity' => '3']]]);
        $service->requestApproval($this->actor, $transfer);
        $transfer = $service->approve($this->actor, $transfer);
        $service->dispatch($this->actor, $transfer);
        $service->receiveNext($this->actor, $transfer);
        $this->project();
        $this->project(location: $warehouse->id);
        $this->assertSame('7.0000', $this->report()['closing']['totals']['quantity']);
        $this->assertSame('14.0000', $this->report()['closing']['totals']['value']);
        $destination = $this->report(location: $warehouse->id);
        $this->assertSame('3.0000', $destination['closing']['totals']['quantity']);
        $this->assertSame('6.0000', $destination['closing']['totals']['value']);
        $this->assertSame('transfer_in', $destination['history']['items'][0]['type']);
        $this->assertSame(1, $destination['history']['total']);
    }

    public function test_restocked_return_uses_authoritative_return_carrying_value(): void
    {
        $this->receive();
        $this->travel(1)->days();
        $delivery = $this->deliver();
        $this->travel(1)->days();
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => '2026-11-03',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '3', 'disposition' => 'restock']]], (string) Str::uuid7());
        $approver = User::factory()->create(['is_active' => true]);
        $approver->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($approver, $this->branch->id);
        $approver->givePermissionTo(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        app(LocationContext::class)->setFor($this->actor, null);
        app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $this->project('2026-11-03');
        $report = $this->report('2026-11-03');
        $this->assertSame('3.0000', $report['closing']['totals']['quantity']);
        $this->assertSame('6.0000', $report['closing']['totals']['value']);
        $this->assertSame('return', $report['history']['items'][0]['type']);
    }

    public function test_cost_permissions_and_branch_isolation_apply_even_to_warm_cache(): void
    {
        $this->receive();
        $this->project();
        $this->report();
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($reader, $this->branch->id);
        $reader->givePermissionTo([InventoryReportingAccess::VIEW, InventoryPermissions::BALANCES, InventoryPermissions::MOVEMENTS]);
        $this->actingAs($reader);
        $service = app(InventoryReadReport::class);
        $report = $service->read($reader, '2026-11-01', '2026-11-01');
        $this->assertFalse($report['costs']);
        $this->assertArrayNotHasKey('value', $report['totals']);
        $this->assertArrayNotHasKey('closing_value', $report['closing']['rows']['items'][0]);
        $reader->givePermissionTo(InventoryPermissions::COST);
        $this->assertSame('20.0000', $service->read($reader, '2026-11-01', '2026-11-01')['closing']['totals']['value']);
        $reader->revokePermissionTo(InventoryPermissions::COST);
        $this->assertFalse($service->read($reader, '2026-11-01', '2026-11-01')['costs']);
        $other = Location::create(['name' => 'Other report branch', 'type' => 'branch', 'is_active' => true]);
        $this->expectException(HttpException::class);
        $service->read($reader, '2026-11-01', '2026-11-01', $other->id);
    }

    public function test_redis_builder_invalidation_is_committed_and_location_scoped(): void
    {
        config(['read_cache.store' => 'redis', 'database.redis.client' => 'predis']);
        app('redis')->connection('cache')->ping();
        $this->postStock('5');
        $other = Location::create(['name' => 'Cache other location', 'type' => 'warehouse', 'is_active' => true]);
        $cache = app(ReadModelCache::class);
        $calls = 0;
        $read = function () use (&$calls): array {
            return ['calls' => ++$calls];
        };
        $deps = [InventoryReportingInvalidation::currentEpoch($this->branch->id)];
        $otherDeps = [InventoryReportingInvalidation::currentEpoch($other->id)];
        $this->assertSame(['calls' => 1], $cache->remember('reporting_inventory', ['probe'], $read, dependencies: $deps));
        $this->assertSame(['calls' => 2], $cache->remember('reporting_inventory', ['other'], $read, dependencies: $otherDeps));
        DB::beginTransaction();
        DB::table('stock_balances')->where('location_id', $this->branch->id)->update(['quantity_reserved' => '1', 'quantity_available' => '4']);
        DB::rollBack();
        $this->assertSame(['calls' => 1], $cache->remember('reporting_inventory', ['probe'], $read, dependencies: $deps));
        DB::transaction(fn () => DB::table('stock_balances')->where('location_id', $this->branch->id)->update(['quantity_reserved' => '1', 'quantity_available' => '4']));
        $this->assertSame(['calls' => 3], $cache->remember('reporting_inventory', ['probe'], $read, dependencies: $deps));
        $this->assertSame(['calls' => 2], $cache->remember('reporting_inventory', ['other'], $read, dependencies: $otherDeps));
        DB::table('stock_balances')->where('variant_id', $this->variant->id)->update(['quantity_reserved' => '2', 'quantity_available' => '3']);
        $this->assertSame(['calls' => 4], $cache->remember('reporting_inventory', ['probe'], $read, dependencies: $deps));
        $this->assertSame(['calls' => 2], $cache->remember('reporting_inventory', ['other'], $read, dependencies: $otherDeps));
    }

    public function test_bounded_backfill_recovery_retries_and_lazy_page(): void
    {
        $this->postStock('2');
        $options = ['--location' => $this->branch->id, '--from' => '2026-11-01', '--until' => '2026-11-02'];
        $this->artisan('reporting:inventory-backfill', $options)->assertFailed();
        try {
            $this->report('2026-11-02');
            $this->fail('Future closing stock must not be presented as historical evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('until', $exception->errors());
        }
        $this->project('2026-11-02');
        $this->assertDatabaseHas('reporting_inventory_daily', ['location_id' => $this->branch->id, 'report_date' => '2026-11-02', 'closing_quantity' => null, 'closing_value' => null]);
        $this->travel(1)->days();
        $this->artisan('reporting:inventory-backfill', $options)->assertSuccessful();
        $this->artisan('reporting:inventory-backfill', array_replace($options, ['--from' => '2026-09-01']))->assertFailed();
        $partition = DB::table('reporting_inventory_partitions')->where('location_id', $this->branch->id)->where('report_date', '2026-11-01');
        $before = $partition->first()->revision;
        for ($i = 0; $i < 4; $i++) {
            app(InventoryProjectionRecovery::class)->scan(500);
        }
        $this->assertSame($before, $partition->first()->revision);
        DB::table('reporting_inventory_daily')->where('location_id', $this->branch->id)->where('report_date', '2026-11-02')->update(['closing_quantity' => '99']);
        Bus::fake([ProjectInventoryDay::class]);
        $recovery = app(InventoryProjectionRecovery::class);
        for ($i = 0; $i < 4; $i++) {
            $recovery->scan(500);
        }
        $recovery->dispatch(500);
        Bus::assertDispatched(ProjectInventoryDay::class, fn ($job): bool => $job->locationId === $this->branch->id && $job->queue === 'reporting');
        $job = new ProjectInventoryDay($this->branch->id, '2026-11-02');
        $job->handle(app(InventoryProjection::class));
        $job->handle(app(InventoryProjection::class));
        $this->assertSame('2.0000', $this->report('2026-11-02')['closing']['totals']['quantity']);
        $this->assertSame(0, $this->report('2026-11-02')['freshness']['pending_days']);
        Livewire::test(InventoryReports::class)->set('from', '2026-11-01')->set('until', '2026-11-02')->set('locationId', $this->branch->id)
            ->call('applyFilters')->assertSuccessful()->assertSee(__('reporting.notes.inventory_basis'));
        Livewire::withoutLazyLoading()->test(DailyInventoryChart::class, ['from' => '2026-11-01', 'until' => '2026-11-02', 'locationId' => $this->branch->id])->assertSuccessful();
    }
}
