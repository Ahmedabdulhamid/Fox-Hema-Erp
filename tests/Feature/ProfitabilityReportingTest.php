<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
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
use App\Modules\Reporting\Filament\Pages\ProfitabilityReports;
use App\Modules\Reporting\Filament\Widgets\DailyProfitabilityChart;
use App\Modules\Reporting\Jobs\ProjectProfitabilityDay;
use App\Modules\Reporting\Services\ProfitabilityAccess;
use App\Modules\Reporting\Services\ProfitabilityInvalidation;
use App\Modules\Reporting\Services\ProfitabilityProjection;
use App\Modules\Reporting\Services\ProfitabilityProjectionRecovery;
use App\Modules\Reporting\Services\ProfitabilityReadReport;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\ProfitabilityReport;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ProfitabilityReportingTest extends TestCase
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
            throw new \LogicException('Profitability tests require a disposable test schema.');
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
        $this->branch = Location::create(['name' => 'Profitability '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->configureSalesPricing($this->variant, '10', '14');
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'profitability-test:'.Str::uuid()]);
    }

    private function deliver(string $method = 'fifo', string $quantity = '10', string $price = '20', string $cost = '2'): SalesDelivery
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
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'items' => [['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => $quantity, 'unit_price' => $price]]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $deliveries = app(SalesDeliveryService::class);
        $draft = $deliveries->create($this->actor, ['sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => $quantity]]], (string) Str::uuid7());

        return app(SalesDeliveryPostingService::class)->post($this->actor, $draft, $deliveries->revision($draft));
    }

    private function project(string $date = '2026-11-01'): void
    {
        $projection = app(ProfitabilityProjection::class);
        $projection->request($this->branch->id, $date);
        $projection->project($this->branch->id, $date);
    }

    private function report(string $until = '2026-11-01', string $group = 'variant'): array
    {
        return app(ProfitabilityReadReport::class)->read($this->actor, '2026-11-01', $until, $this->branch->id, group: $group);
    }

    public static function costingMethods(): array
    {
        return [['fifo'], ['avco'], ['standard']];
    }

    #[DataProvider('costingMethods')]
    public function test_delivery_based_projection_matches_authoritative_rules_and_retries(string $method): void
    {
        $this->deliver($method);
        $this->assertDatabaseHas('reporting_profitability_partitions', ['location_id' => $this->branch->id, 'report_date' => '2026-11-01']);
        $projection = app(ProfitabilityProjection::class);
        $projection->project($this->branch->id, '2026-11-01');
        (new ProjectProfitabilityDay($this->branch->id, '2026-11-01'))->handle($projection);
        $report = $this->report();
        $source = app(ProfitabilityReport::class);
        $expected = $source->summary($source->events($this->actor, '2026-11-01', '2026-11-01', $this->branch->id));
        foreach (['revenue', 'cost', 'profit', 'margin', 'missing'] as $field) {
            $this->assertSame($expected[$field], $report['totals'][$field]);
        }
        $this->assertSame('180.0000', $report['totals']['revenue']);
        $this->assertSame('20.0000', $report['totals']['cost']);
        $this->assertSame('160.0000', $report['totals']['profit']);
        $this->assertSame('88.89', $report['totals']['margin']);
        $this->assertSame($report['totals']['profit'], $this->report(group: 'product')['breakdown'][0]['profit']);
        $this->assertSame(1, DB::table('reporting_profitability_daily')->where('location_id', $this->branch->id)->count());
        $this->assertDatabaseMissing('sales_invoices', ['sales_order_id' => DB::table('sales_deliveries')->where('location_id', $this->branch->id)->value('sales_order_id')]);
    }

    public function test_missing_fifo_evidence_is_unknown_and_recovery_is_idempotent(): void
    {
        $delivery = $this->deliver();
        $this->project();
        $entry = InventoryCostEntry::where('stock_movement_id', $delivery->items()->firstOrFail()->stock_movement_id)->firstOrFail();
        DB::table('fifo_layer_consumptions')->where('cost_entry_id', $entry->id)->update(['total_cost' => '0']);
        $projection = app(ProfitabilityProjection::class);
        $projection->requestIfChanged($this->branch->id, '2026-11-01');
        $this->assertSame(1, app(ProfitabilityReadReport::class)->freshness($this->actor, '2026-11-01', '2026-11-01', $this->branch->id)['pending_days']);
        $projection->project($this->branch->id, '2026-11-01');
        $report = $this->report();
        $this->assertSame(1, $report['totals']['missing']);
        foreach (['cost', 'profit', 'margin'] as $field) {
            $this->assertNull($report['totals'][$field]);
        }
        $this->assertSame([], $report['top']);
        $before = DB::table('reporting_profitability_partitions')->where('location_id', $this->branch->id)->first();
        $recovery = app(ProfitabilityProjectionRecovery::class);
        for ($cycle = 0; $cycle < 4; $cycle++) {
            $recovery->scan(500);
        }
        $after = DB::table('reporting_profitability_partitions')->where('location_id', $this->branch->id)->first();
        $this->assertSame($before->revision, $after->revision);
    }

    public function test_returns_reverse_original_cogs_once_and_credit_adjustments_use_posting_dates(): void
    {
        $delivery = $this->deliver();
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => '2026-11-01',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '10']]], (string) Str::uuid7());
        $invoice = $invoices->post($this->actor, $invoice, $invoices->revision($invoice));
        $this->travel(1)->days();
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => '2026-11-02',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '3', 'disposition' => 'scrap', 'notes' => 'Damaged return']]], (string) Str::uuid7());
        $approver = $this->reader(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $return = app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $this->travel(1)->days();
        $credits = app(SalesCreditNoteService::class);
        $credit = $credits->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
            'credit_date' => '2026-11-02', 'items' => [['sales_return_posted_allocation_id' => $return->postedAllocations()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());
        $credits->post($this->actor, $credit, $credits->revision($credit));
        foreach (['2026-11-01', '2026-11-02', '2026-11-03'] as $date) {
            $this->assertDatabaseHas('reporting_profitability_partitions', ['location_id' => $this->branch->id, 'report_date' => $date]);
            app(ProfitabilityProjection::class)->project($this->branch->id, $date);
        }
        $report = $this->report('2026-11-03');
        $this->assertSame('126.0000', $report['totals']['revenue']);
        $this->assertSame('14.0000', $report['totals']['cost']);
        $this->assertSame('112.0000', $report['totals']['profit']);
        $this->assertSame('3.0000', $report['totals']['return_quantity']);
        $this->assertSame('54.0000', $report['totals']['return_revenue']);
        $this->assertSame('6.0000', $report['totals']['return_loss']);
        $this->assertSame('0.0000', $report['totals']['credit_adjustment']);
        $entry = InventoryCostEntry::where('stock_movement_id', $delivery->items()->firstOrFail()->stock_movement_id)->firstOrFail();
        app(ProfitabilityInvalidation::class)->evidence($entry);
        $this->assertSame(3, app(ProfitabilityReadReport::class)->freshness($this->actor, '2026-11-01', '2026-11-03', $this->branch->id)['pending_days']);
    }

    private function reader(array $permissions): User
    {
        $reader = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $reader->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($reader, $this->branch->id);
        $reader->givePermissionTo($permissions);
        $this->actingAs($reader);

        return $reader;
    }

    public function test_cost_permission_is_required_even_for_cached_reports_and_other_branches_are_rejected(): void
    {
        $this->deliver();
        $this->project();
        $this->report();
        $reader = $this->reader([ProfitabilityAccess::VIEW, ...SalesPermissions::all(), InventoryPermissions::COST]);
        $service = app(ProfitabilityReadReport::class);
        $this->assertSame('160.0000', $service->read($reader, '2026-11-01', '2026-11-01')['totals']['profit']);
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        try {
            $service->read($reader, '2026-11-01', '2026-11-01', $other->id);
            $this->fail('Cross-branch cost data must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $reader->revokePermissionTo(InventoryPermissions::COST);
        $this->expectException(HttpException::class);
        $service->read($reader, '2026-11-01', '2026-11-01');
    }

    public function test_redis_epochs_change_only_after_a_committed_projection_for_the_affected_day(): void
    {
        config(['read_cache.store' => 'redis', 'database.redis.client' => 'predis']);
        app('redis')->connection('cache')->ping();
        $this->deliver();
        $this->project();
        $cache = app(ReadModelCache::class);
        $calls = 0;
        $read = function () use (&$calls): array {
            return ['calls' => ++$calls];
        };
        $dependencies = [ProfitabilityProjection::epoch($this->branch->id, '2026-11-01')];
        $this->assertSame(['calls' => 1], $cache->remember('reporting_profitability', ['probe'], $read, dependencies: $dependencies));
        $this->project('2026-11-02');
        $this->assertSame(['calls' => 1], $cache->remember('reporting_profitability', ['probe'], $read, dependencies: $dependencies));
        $projection = app(ProfitabilityProjection::class);
        $projection->request($this->branch->id, '2026-11-01');
        DB::beginTransaction();
        $projection->project($this->branch->id, '2026-11-01');
        DB::rollBack();
        $this->assertSame(['calls' => 1], $cache->remember('reporting_profitability', ['probe'], $read, dependencies: $dependencies));
        $this->assertSame(1, app(ProfitabilityReadReport::class)->freshness($this->actor, '2026-11-01', '2026-11-01', $this->branch->id)['pending_days']);
        $projection->project($this->branch->id, '2026-11-01');
        $this->assertSame(['calls' => 2], $cache->remember('reporting_profitability', ['probe'], $read, dependencies: $dependencies));
    }

    public function test_backfill_is_bounded_recovery_resumes_missed_hooks_and_lazy_page_renders(): void
    {
        $this->deliver();
        $options = ['--location' => $this->branch->id, '--from' => '2026-11-01', '--until' => '2026-11-02'];
        $this->artisan('reporting:profitability-backfill', $options)->assertSuccessful();
        $this->artisan('reporting:profitability-backfill', $options)->assertSuccessful();
        $this->assertSame(2, app(ProfitabilityReadReport::class)->freshness($this->actor, '2026-11-01', '2026-11-02', $this->branch->id)['projected_days']);
        $this->artisan('reporting:profitability-backfill', array_replace($options, ['--until' => '2026-12-02']))->assertFailed();
        DB::table('reporting_profitability_daily')->where('location_id', $this->branch->id)->update(['revenue' => '1']);
        Bus::fake([ProjectProfitabilityDay::class]);
        $recovery = app(ProfitabilityProjectionRecovery::class);
        for ($cycle = 0; $cycle < 4; $cycle++) {
            $recovery->scan(500);
        }
        $recovery->dispatch(500);
        Bus::assertDispatched(ProjectProfitabilityDay::class, fn ($job): bool => $job->locationId === $this->branch->id && $job->queue === 'reporting');
        (new ProjectProfitabilityDay($this->branch->id, '2026-11-01'))->handle(app(ProfitabilityProjection::class));
        $this->assertSame('180.0000', $this->report()['totals']['revenue']);
        Livewire::test(ProfitabilityReports::class)->set('from', '2026-11-01')->set('until', '2026-11-02')
            ->set('locationId', $this->branch->id)->call('applyFilters')->assertSuccessful()->assertSee('Top profitable variants');
        Livewire::withoutLazyLoading()->test(DailyProfitabilityChart::class, ['from' => '2026-11-01', 'until' => '2026-11-02', 'locationId' => $this->branch->id])->assertSuccessful();
    }

    public function test_four_decimal_residuals_and_zero_revenue_keep_authoritative_precision(): void
    {
        $this->deliver(quantity: '3', price: '0.000050', cost: '0.000001');
        $this->project();
        $source = app(ProfitabilityReport::class);
        $expected = $source->summary($source->events($this->actor, '2026-11-01', '2026-11-01', $this->branch->id));
        $this->assertSame($expected['revenue'], $this->report()['totals']['revenue']);
        $this->assertSame($expected['cost'], $this->report()['totals']['cost']);
        $this->assertSame($expected['profit'], $this->report()['totals']['profit']);
    }
}
