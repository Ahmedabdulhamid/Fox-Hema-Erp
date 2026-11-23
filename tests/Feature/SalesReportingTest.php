<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Reporting\Filament\Pages\SalesReports;
use App\Modules\Reporting\Filament\Widgets\DailySalesChart;
use App\Modules\Reporting\Jobs\ProjectSalesDay;
use App\Modules\Reporting\Services\ReportingAccess;
use App\Modules\Reporting\Services\SalesProjection;
use App\Modules\Reporting\Services\SalesProjectionRecovery;
use App\Modules\Reporting\Services\SalesReport;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNoteItem;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
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
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SalesReportingTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    /** Exercise real commits and cache epochs in the runner's disposable schema. */
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
            throw new \LogicException('Reporting tests require the disposable MySQL bootstrap.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->branch = Location::create(['name' => 'Reporting '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create();
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'reporting-test:'.Str::uuid()]);
    }

    private function invoice(string $amount = '10.0001', ?Location $branch = null, bool $posted = true): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => ($branch ?? $this->branch)->id,
            'created_by' => $this->actor->id, 'invoice_date' => '2026-10-17', 'subtotal' => $amount, 'total_amount' => $amount]);
        SalesInvoiceItem::factory()->create(['sales_invoice_id' => $invoice->id, 'variant_id' => $this->variant->id,
            'quantity' => '1.0000', 'subtotal' => $amount, 'line_total' => $amount]);
        if ($posted) {
            DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        }

        return $invoice->refresh();
    }

    private function credit(SalesInvoice $invoice, string $amount = '2.0001', string $date = '2026-10-17'): int
    {
        $attributes = SalesCreditNote::factory()->make(['sales_invoice_id' => $invoice->id,
            'location_id' => $invoice->location_id, 'created_by' => $this->actor->id, 'credit_date' => $date,
            'subtotal' => $amount, 'total_amount' => $amount])->getAttributes();
        $id = DB::table('sales_credit_notes')->insertGetId($attributes + ['uuid' => (string) Str::uuid7(),
            'created_at' => now(), 'updated_at' => now()]);
        $item = SalesCreditNoteItem::factory()->make(['sales_credit_note_id' => $id,
            'sales_invoice_item_id' => $invoice->items()->firstOrFail()->id,
            'quantity' => '0.2000', 'subtotal' => $amount, 'line_total' => $amount])->getAttributes();
        DB::table('sales_credit_note_items')->insert($item + ['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_credit_notes')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);

        return $id;
    }

    private function project(string $date = '2026-10-17', ?Location $branch = null): void
    {
        $projection = app(SalesProjection::class);
        $projection->request(($branch ?? $this->branch)->id, $date);
        $projection->project(($branch ?? $this->branch)->id, $date);
    }

    public function test_only_posted_documents_are_projected_once_with_exact_rounding(): void
    {
        $invoice = $this->invoice();
        $this->invoice('99', posted: false);
        $cancelled = $this->invoice('80');
        DB::table('sales_invoices')->where('id', $cancelled->id)->update(['cancelled_at' => now()]);
        $this->credit($invoice);
        $draft = $this->credit($invoice, '1');
        DB::table('sales_credit_notes')->where('id', $draft)->update(['status' => 'draft', 'posted_at' => null]);
        $cancelledCredit = $this->credit($invoice, '1');
        DB::table('sales_credit_notes')->where('id', $cancelledCredit)->update(['cancelled_at' => now()]);
        $this->project();
        $job = new ProjectSalesDay($this->branch->id, '2026-10-17');
        $job->handle(app(SalesProjection::class));
        $job->handle(app(SalesProjection::class));
        $report = app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id);
        $this->assertSame('8.0000', $report['totals']['net_total']);
        $this->assertSame(1, $report['totals']['invoice_count']);
        $this->assertSame(1, $report['totals']['credit_count']);
        $this->assertSame('0.8000', $report['variants'][0]['net_quantity']);
        $this->assertSame('8.0000', $report['variants'][0]['net_revenue']);
        $this->assertSame('8.0000', $report['daily'][0]['net_revenue']);
    }

    public function test_credit_uses_its_own_document_date_and_does_not_repeat_invoice_revenue(): void
    {
        $this->credit($this->invoice(), date: '2026-10-18');
        $this->project();
        $this->project('2026-10-18');
        $report = app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-18', $this->branch->id);
        $this->assertSame(['10.0001', '-2.0001'], array_column($report['daily'], 'net_revenue'));
        $this->assertSame('8.0000', $report['totals']['net_revenue']);
    }

    public function test_projection_preserves_header_totals_without_join_multiplication(): void
    {
        $invoice = $this->invoice(posted: false);
        SalesInvoiceItem::factory()->create(['sales_invoice_id' => $invoice->id, 'variant_id' => $this->variant->id,
            'quantity' => '1', 'subtotal' => '0.0002', 'line_total' => '0.0002']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(),
            'subtotal' => '10.0003', 'discount_amount' => '0.0001', 'tax_amount' => '1.0001', 'total_amount' => '11.0003']);
        $this->project();
        $totals = app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals'];
        $this->assertSame('11.0003', $totals['invoiced_total']);
        $this->assertSame('10.0002', $totals['net_revenue']);
        $this->assertSame('1.0001', $totals['net_tax']);
        $this->assertSame(1, $totals['invoice_count']);
    }

    public function test_branch_filters_and_cached_results_cannot_escape_current_branch(): void
    {
        $other = Location::create(['name' => 'Other reporting branch', 'type' => 'branch', 'is_active' => true]);
        $this->invoice();
        $this->invoice('200', $other);
        $this->project();
        $this->project(branch: $other);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($this->branch);
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $this->branch->id);
        $actor->givePermissionTo([ReportingAccess::VIEW, SalesPermissions::INVOICES_VIEW, SalesPermissions::CREDITS_VIEW]);
        $report = app(SalesReport::class)->read($actor, '2026-10-17', '2026-10-17');
        $this->assertSame('10.0001', $report['totals']['net_total']);
        $this->assertSame([$this->branch->id], array_keys(app(ReportingAccess::class)->branches($actor)));
        $this->expectException(HttpException::class);
        app(SalesReport::class)->read($actor, '2026-10-17', '2026-10-17', $other->id);
    }

    public function test_redis_cache_invalidation_is_scoped_to_the_committed_branch_day(): void
    {
        config(['read_cache.store' => 'redis', 'database.redis.client' => 'predis']);
        app('redis')->connection('cache')->ping();
        $invoice = $this->invoice();
        $this->project();
        $cache = app(ReadModelCache::class);
        $this->assertSame('10.0001', app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals']['net_total']);
        $calls = 0;
        $read = function () use (&$calls): array {
            return ['calls' => ++$calls];
        };
        $dependency = [SalesProjection::epoch($this->branch->id, '2026-10-17')];
        $this->assertSame(['calls' => 1], $cache->remember('reporting_sales', ['probe'], $read, dependencies: $dependency));
        $this->credit($invoice);
        $this->project('2026-10-18');
        $this->assertSame(['calls' => 1], $cache->remember('reporting_sales', ['probe'], $read, dependencies: $dependency));
        DB::beginTransaction();
        $this->project();
        DB::rollBack();
        $this->assertSame(['calls' => 1], $cache->remember('reporting_sales', ['probe'], $read, dependencies: $dependency));
        $this->project();
        $this->assertSame(['calls' => 2], $cache->remember('reporting_sales', ['probe'], $read, dependencies: $dependency));
        $this->assertSame('8.0000', app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals']['net_total']);
    }

    public function test_projection_rollback_keeps_pending_work_and_old_totals(): void
    {
        $invoice = $this->invoice();
        $this->project();
        $this->credit($invoice);
        app(SalesProjection::class)->request($this->branch->id, '2026-10-17');
        DB::beginTransaction();
        app(SalesProjection::class)->project($this->branch->id, '2026-10-17');
        DB::rollBack();
        $this->assertSame('10.0001', (string) DB::table('reporting_sales_daily')->where('location_id', $this->branch->id)->value('invoice_total'));
        $this->assertSame('0.0000', (string) DB::table('reporting_sales_daily')->where('location_id', $this->branch->id)->value('credit_total'));
        $freshness = app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-18', $this->branch->id);
        $this->assertSame(1, $freshness['pending_days']);
        $this->assertSame(1, $freshness['projected_days']);
        $this->assertSame(2, $freshness['expected_days']);
        app(SalesProjection::class)->project($this->branch->id, '2026-10-17');
        $this->assertSame('8.0000', app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals']['net_total']);
    }

    public function test_backfill_is_bounded_repeatable_and_tracks_empty_days(): void
    {
        $this->invoice();
        $options = ['--location' => $this->branch->id, '--from' => '2026-10-17', '--until' => '2026-10-18'];
        $this->artisan('reporting:sales-backfill', $options)->assertSuccessful();
        $this->artisan('reporting:sales-backfill', $options)->assertSuccessful();
        $this->assertSame(1, DB::table('reporting_sales_daily')->where('location_id', $this->branch->id)->count());
        $this->assertSame(2, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-18', $this->branch->id)['projected_days']);
        $this->artisan('reporting:sales-backfill', array_replace($options, ['--until' => '2026-12-18']))->assertFailed();
    }

    public function test_recovery_finds_missed_hooks_and_retries_expired_dispatch_leases(): void
    {
        Bus::fake([ProjectSalesDay::class]);
        $this->invoice();
        $recovery = app(SalesProjectionRecovery::class);
        for ($index = 0; $index < 30; $index++) {
            $recovery->scan(2);
            if (DB::table('reporting_sales_partitions')->where('location_id', $this->branch->id)->exists()) {
                break;
            }
        }
        $this->assertDatabaseHas('reporting_sales_partitions', ['location_id' => $this->branch->id, 'report_date' => '2026-10-17']);
        $recovery->dispatch(500);
        Bus::assertDispatched(ProjectSalesDay::class, fn ($job): bool => $job->locationId === $this->branch->id);
        $this->assertSame(0, $recovery->dispatch(500));
        DB::table('reporting_sales_partitions')->where('location_id', $this->branch->id)->update(['dispatched_at' => now()->subMinutes(6)]);
        $this->assertSame(1, $recovery->dispatch(500));
        (new ProjectSalesDay($this->branch->id, '2026-10-17'))->handle(app(SalesProjection::class));
        $this->assertSame('10.0001', app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals']['net_total']);
    }

    public function test_unchanged_recovery_cycles_do_not_mark_current_days_pending(): void
    {
        $invoice = $this->invoice();
        $this->credit($invoice);
        $this->project();
        $this->project('2026-10-18');
        $before = DB::table('reporting_sales_partitions')->where('location_id', $this->branch->id)->orderBy('id')->get()->toJson();
        $recovery = app(SalesProjectionRecovery::class);
        for ($index = 0; $index < 4; $index++) {
            $recovery->scan(500);
        }
        $this->assertSame($before, DB::table('reporting_sales_partitions')->where('location_id', $this->branch->id)->orderBy('id')->get()->toJson());
        $this->assertSame(0, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-18', $this->branch->id)['pending_days']);
    }

    public function test_missed_credit_hook_remains_pending_until_projection_commits(): void
    {
        $invoice = $this->invoice();
        $this->project();
        $this->credit($invoice);
        $projection = app(SalesProjection::class);
        $projection->requestIfChanged($this->branch->id, '2026-10-17');
        $projection->requestIfChanged($this->branch->id, '2026-10-17');
        $this->assertDatabaseHas('reporting_sales_partitions', ['location_id' => $this->branch->id, 'revision' => 2, 'projected_revision' => 1]);
        $this->assertSame(1, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['pending_days']);
        DB::beginTransaction();
        $projection->project($this->branch->id, '2026-10-17');
        DB::rollBack();
        $this->assertSame(1, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['pending_days']);
        $projection->project($this->branch->id, '2026-10-17');
        $this->assertSame(0, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['pending_days']);
        $this->assertSame('8.0000', app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['totals']['net_total']);
    }

    public function test_recovery_detects_variant_drift_even_when_header_totals_and_source_timestamps_match(): void
    {
        $this->invoice();
        $this->project();
        DB::table('reporting_sales_variant_daily')->where('location_id', $this->branch->id)->update(['invoice_quantity' => '9.0000']);
        $projection = app(SalesProjection::class);
        $projection->requestIfChanged($this->branch->id, '2026-10-17');
        $this->assertSame(1, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['pending_days']);
        $projection->project($this->branch->id, '2026-10-17');
        $this->assertSame('1.0000', (string) DB::table('reporting_sales_variant_daily')->where('location_id', $this->branch->id)->value('invoice_quantity'));
        $this->assertSame(0, app(SalesReport::class)->freshness($this->actor, '2026-10-17', '2026-10-17', $this->branch->id)['pending_days']);
    }

    public function test_warning_clears_only_after_pending_work_and_missing_coverage_are_resolved(): void
    {
        $invoice = $this->invoice();
        $this->project();
        $warning = __('reporting.freshness.provisional');
        $page = Livewire::test(SalesReports::class)->set('from', '2026-10-17')->set('until', '2026-10-18')
            ->set('locationId', $this->branch->id)->call('applyFilters')->assertSee($warning);
        $this->project('2026-10-18');
        $page->call('$refresh')->assertDontSee($warning);
        $this->credit($invoice);
        $projection = app(SalesProjection::class);
        $projection->requestIfChanged($this->branch->id, '2026-10-17');
        $page->call('$refresh')->assertSee($warning);
        $projection->project($this->branch->id, '2026-10-17');
        $page->call('$refresh')->assertDontSee($warning);
    }

    public function test_page_and_lazy_chart_render_authorized_sales_and_deny_revoked_access(): void
    {
        $this->invoice();
        $this->project();
        Livewire::test(SalesReports::class)->set('from', '2026-10-17')->set('until', '2026-10-17')
            ->set('locationId', $this->branch->id)->call('applyFilters')->assertSuccessful()->assertSee('Top-selling variants');
        Livewire::withoutLazyLoading()->test(DailySalesChart::class, ['from' => '2026-10-17', 'until' => '2026-10-17', 'locationId' => $this->branch->id])->assertSuccessful();
        Module::where('code', 'reporting')->update(['is_active' => false]);
        $this->assertFalse(SalesReports::canAccess());
        Livewire::test(SalesReports::class)->assertForbidden();
    }

    /** @return array{SalesInvoice, SalesDelivery} */
    private function invoiceDraftThroughServices(): array
    {
        $category = Category::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create([
            'category_id' => $category->id, 'status' => 'active', 'is_active' => true,
        ])->id]);
        $this->configureSalesPricing($variant);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $purchase = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
            'location_id' => $this->branch->id, 'order_date' => '2026-10-17', 'items' => [[
                'product_id' => $variant->product_id, 'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'],
                'variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '2',
            ]]], (string) Str::uuid7());
        $purchase = $orders->confirm($this->actor, $purchase, $orders->revision($purchase));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $purchase->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => '3', 'unit_cost' => '2']]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'customer_name' => 'Reporting customer', 'items' => [['product_id' => $variant->product_id,
                'variant_id' => $variant->id, 'quantity' => '3', 'unit_price' => '10.123456']]], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($this->actor, ['sales_order_id' => $order->id,
            'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());
        $delivery = app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $deliveries->revision($delivery));
        $invoice = app(SalesInvoiceService::class)->create($this->actor, ['sales_order_id' => $order->id, 'invoice_date' => '2026-10-17',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '3']]], (string) Str::uuid7());

        return [$invoice, $delivery];
    }

    public function test_real_invoice_and_credit_posting_request_durable_projections_without_changing_totals(): void
    {
        [$invoice, $delivery] = $this->invoiceDraftThroughServices();
        $invoices = app(SalesInvoiceService::class);
        $invoice = $invoices->post($this->actor, $invoice, $invoices->revision($invoice));
        $invoices->post($this->actor, $invoice, $invoice->posting_revision);
        $this->assertDatabaseHas('reporting_sales_partitions', ['location_id' => $this->branch->id, 'report_date' => '2026-10-17', 'revision' => 1]);
        $returns = app(SalesReturnService::class);
        $return = $returns->create($this->actor, ['sales_order_id' => $invoice->sales_order_id, 'return_date' => '2026-10-18',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => '1', 'disposition' => 'restock']]], (string) Str::uuid7());
        $approver = User::factory()->create(['is_active' => true]);
        $approver->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($approver, $this->branch->id);
        $approver->givePermissionTo(SalesPermissions::all());
        $this->actingAs($approver);
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $return = app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $this->project();
        $this->project('2026-10-18');
        $this->assertSame($invoice->total_amount, app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-18', $this->branch->id)['totals']['net_total']);
        $credits = app(SalesCreditNoteService::class);
        $note = $credits->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
            'credit_date' => '2026-10-18', 'items' => [['sales_return_posted_allocation_id' => $return->postedAllocations()->firstOrFail()->id,
                'quantity' => '1']]], (string) Str::uuid7());
        $note = $credits->post($this->actor, $note, $credits->revision($note));
        $credits->post($this->actor, $note, $note->posting_revision);
        $this->assertDatabaseHas('reporting_sales_partitions', ['location_id' => $this->branch->id, 'report_date' => '2026-10-18', 'revision' => 2]);
        app(SalesProjection::class)->project($this->branch->id, '2026-10-17');
        app(SalesProjection::class)->project($this->branch->id, '2026-10-18');
        $report = app(SalesReport::class)->read($this->actor, '2026-10-17', '2026-10-18', $this->branch->id);
        $this->assertSame((string) BigDecimal::of($invoice->total_amount)->minus($note->total_amount)->toScale(4), $report['totals']['net_total']);
        $this->assertSame(1, $report['totals']['invoice_count']);
        $this->assertSame(1, $report['totals']['credit_count']);
        $this->assertSame($invoice->total_amount, $invoice->fresh()->total_amount);
    }

    public function test_failed_posting_rolls_back_the_durable_request_together_with_the_invoice(): void
    {
        [$invoice] = $this->invoiceDraftThroughServices();
        try {
            DB::transaction(function () use ($invoice): void {
                $service = app(SalesInvoiceService::class);
                $service->post($this->actor, $invoice, $service->revision($invoice));
                throw new RuntimeException('Simulated outer transaction failure');
            });
        } catch (RuntimeException) {
            $this->assertSame('draft', $invoice->fresh()->status);
            $this->assertDatabaseMissing('reporting_sales_partitions', ['location_id' => $this->branch->id]);
        }
    }
}
