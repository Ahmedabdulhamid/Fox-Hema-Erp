<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosOfflineSaleSyncService;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\POS\Services\PosTerminalCatalog;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Reporting\Filament\Pages\InventoryReports;
use App\Modules\Reporting\Filament\Pages\PosReports;
use App\Modules\Reporting\Filament\Pages\ProfitabilityReports;
use App\Modules\Reporting\Filament\Pages\SalesReports;
use App\Modules\Reporting\Filament\Pages\TreasuryReports;
use App\Modules\Reporting\Filament\Widgets\DailyCashChart;
use App\Modules\Reporting\Jobs\ProjectCashDay;
use App\Modules\Reporting\Services\CashProjectionRecovery;
use App\Modules\Reporting\Services\DailyProjectionState;
use App\Modules\Reporting\Services\PosCollectionReport;
use App\Modules\Reporting\Services\PosProjection;
use App\Modules\Reporting\Services\PosReadReport;
use App\Modules\Reporting\Services\PosReportingAccess;
use App\Modules\Reporting\Services\ReportingAccess;
use App\Modules\Reporting\Services\SalesReport;
use App\Modules\Reporting\Services\TreasuryProjection;
use App\Modules\Reporting\Services\TreasuryReadReport;
use App\Modules\Reporting\Services\TreasuryReportingAccess;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TreasuryPosReportingTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private TreasuryAccount $cash;

    private PosSession $shift;

    private ProductVariant $variant;

    private PaymentMethod $method;

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
            throw new \LogicException('Disposable test schema required.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetInstance(DocumentNumberService::class);
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->actor = app(SuperAdminProvisioner::class)->provision('cash-report-'.Str::uuid().'@example.test', false, 'Report administrator', 'a-secure-test-password');
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->branch = Location::create(['name' => 'Cash reporting '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->actor, $this->branch->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'cash-report:'.Str::uuid()]);
    }

    private function operation(string $type, string $amount, ?int $destination = null): TreasuryOperation
    {
        $service = app(TreasuryService::class);
        $data = ['type' => $type, 'account_id' => $this->cash->id, 'amount' => $amount, 'effective_date' => today()->toDateString(), 'reason' => 'Reporting test', 'source_type' => 'external_capital', 'source_reference' => (string) Str::uuid7()];
        if ($destination !== null) {
            $data['destination_account_id'] = $destination;
        }

        return $service->post($this->actor, $service->createDraft($this->actor, $data, (string) Str::uuid7()));
    }

    private function projectTreasury(?int $account = null, string $date = '2026-11-01'): void
    {
        $p = app(TreasuryProjection::class);
        $p->request($account ?? $this->cash->id, $date);
        $p->project($account ?? $this->cash->id, $date);
    }

    private function projectPos(string $date = '2026-11-01'): void
    {
        $p = app(PosProjection::class);
        $p->request($this->branch->id, $date);
        $p->project($this->branch->id, $date);
    }

    private function posReport(string $until = '2026-11-01'): array
    {
        return app(PosReadReport::class)->read($this->actor, '2026-11-01', $until, $this->branch->id);
    }

    private function assertProjectionCacheEpochWasInvalidated(string $domain, int $scopeId, string $date): void
    {
        $key = config('read_cache.prefix').':durable-version:'.hash('sha256', json_encode([
            app()->environment(),
            DB::connection()->getName(),
            DB::connection()->getDatabaseName(),
            DailyProjectionState::epoch($domain, $scopeId, $date),
        ], JSON_THROW_ON_ERROR));

        $this->assertDatabaseHas(config('cache.stores.database.table', 'cache'), ['key' => $key]);
    }

    public function test_treasury_projection_job_uses_account_partition_key(): void
    {
        $date = '2026-11-01';
        $projection = app(TreasuryProjection::class);
        $projection->request($this->cash->id, $date);
        (new CashProjectionRecovery('treasury'))->scan(500);
        $projection->request($this->cash->id, $date);

        $this->assertDatabaseHas('reporting_treasury_partitions', [
            'account_id' => $this->cash->id,
            'report_date' => $date,
            'revision' => 2,
            'projected_revision' => 0,
        ]);

        (new ProjectCashDay('treasury', $this->cash->id, $date))->handle();

        $this->assertDatabaseHas('reporting_treasury_partitions', [
            'account_id' => $this->cash->id,
            'report_date' => $date,
            'revision' => 2,
            'projected_revision' => 2,
        ]);
        $this->assertTrue($projection->isCurrent($this->cash->id, $date));
        $this->assertProjectionCacheEpochWasInvalidated('treasury', $this->cash->id, $date);
    }

    public function test_pos_projection_job_uses_location_partition_key(): void
    {
        $date = '2026-11-01';
        $projection = app(PosProjection::class);
        $projection->request($this->branch->id, $date);
        (new CashProjectionRecovery('pos'))->scan(500);
        $projection->request($this->branch->id, $date);

        $this->assertDatabaseHas('reporting_pos_partitions', [
            'location_id' => $this->branch->id,
            'report_date' => $date,
            'revision' => 2,
            'projected_revision' => 0,
        ]);

        (new ProjectCashDay('pos', $this->branch->id, $date))->handle();

        $this->assertDatabaseHas('reporting_pos_partitions', [
            'location_id' => $this->branch->id,
            'report_date' => $date,
            'revision' => 2,
            'projected_revision' => 2,
        ]);
        $this->assertTrue($projection->isCurrent($this->branch->id, $date));
        $this->assertProjectionCacheEpochWasInvalidated('pos', $this->branch->id, $date);
    }

    public function test_reporting_pages_render_localized_titles_filters_and_direction(): void
    {
        $pages = [
            SalesReports::class => 'sales',
            ProfitabilityReports::class => 'profitability',
            InventoryReports::class => 'inventory',
            TreasuryReports::class => 'treasury',
            PosReports::class => 'pos',
        ];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $direction = $locale === 'ar' ? 'rtl' : 'ltr';

            foreach ($pages as $page => $key) {
                $component = Livewire::test($page)->call('loadReport');
                $component
                    ->assertSee(__('reporting.pages.'.$key.'.title'))
                    ->assertSee(__('reporting.pages.'.$key.'.description'))
                    ->assertSee(__('reporting.filters.from'))
                    ->assertSee(__('reporting.filters.apply'))
                    ->assertSee(__('reporting.filters.reset'))
                    ->assertSee('dir="'.$direction.'"', false);

                if ($locale === 'ar') {
                    $component->assertDontSee('Apply filters')->assertDontSee('From date');
                }

                $component
                    ->set('from', '2026-10-25')
                    ->set('until', '2026-10-30')
                    ->set('locationId', $this->branch->id);

                if ($page === ProfitabilityReports::class) {
                    $component->set('group', 'product');
                } elseif ($page === TreasuryReports::class) {
                    $component->set('accountId', $this->cash->id);
                }

                $component->call('resetFilters')
                    ->assertSet('from', '2026-11-01')
                    ->assertSet('until', '2026-11-01')
                    ->assertSet('locationId', null);

                if ($page === ProfitabilityReports::class) {
                    $component->assertSet('group', 'variant');
                } elseif ($page === TreasuryReports::class) {
                    $component->assertSet('accountId', null);
                }
            }
        }
    }

    private function prepareCheckout(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true, 'has_fixed_selling_price' => true, 'sale_price' => '100']);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        $this->configureSalesPricing($this->variant, '0', '0');
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id, 'order_date' => today()->toDateString(), 'items' => [[
            'product_id' => $product->id, 'variant_id' => $this->variant->id, 'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'], 'quantity' => '10', 'unit_price' => '20']]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id, 'quantity' => '10', 'unit_cost' => '20']]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $this->method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $devices = app(PosDeviceManagement::class);
        $device = $devices->register($this->actor, ['name' => 'Report terminal', 'location_id' => $this->branch->id]);
        $devices->configurePaymentMethod($this->actor, $device, $this->method->id, $this->cash->id);
        $this->shift = app(PosShiftService::class)->open($this->actor, $device->id, $this->cash->id, '0', (string) Str::uuid7());
    }

    private function sale(?string $key = null): PosTransaction
    {
        return app(PosCheckoutService::class)->checkout($this->actor, $this->shift->id, $key ?? (string) Str::uuid7(), [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '1']],
            'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']],
            'cash_received' => $this->method->type === 'cash' ? '100' : '0',
        ]);
    }

    public function test_transfers_openings_and_reversals_are_separate_from_external_flow(): void
    {
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->actor->id]);
        $this->operation('opening_balance', '100.1234');
        $transfer = $this->operation('transfer', '10.0001', $central->id);
        $deposit = $this->operation('cash_deposit', '5.0001');
        app(TreasuryService::class)->reverse($this->actor, $deposit, (string) Str::uuid7(), 'Undo external deposit');
        $this->projectTreasury();
        $this->projectTreasury($central->id);
        $r = app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01');
        $this->assertSame('10.0001', $r['totals']['internal_in']);
        $this->assertSame('10.0001', $r['totals']['internal_out']);
        $this->assertSame('5.0001', $r['totals']['external_in']);
        $this->assertSame('5.0001', $r['totals']['external_out']);
        $this->assertSame('100.1234', $r['totals']['opening_in']);
        foreach ($r['balances'] as $row) {
            $this->assertSame(app(TreasuryService::class)->balanceForDisplay($this->actor, TreasuryAccount::findOrFail($row['id'])), $row['balance']);
        }
        app(TreasuryService::class)->reverse($this->actor, $transfer, (string) Str::uuid7(), 'Undo transfer');
        $this->projectTreasury();
        $this->projectTreasury($central->id);
        $this->assertSame('100.1234', app(TreasuryService::class)->balanceForDisplay($this->actor, $this->cash));
        $centralRows = app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', 0);
        $this->assertSame([$central->id], array_keys($centralRows['accounts']));
    }

    public function test_treasury_account_and_balance_authorization_precedes_cached_reads(): void
    {
        $this->operation('opening_balance', '10');
        $this->projectTreasury();
        app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01');
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($reader, $this->branch->id);
        $reader->givePermissionTo([TreasuryReportingAccess::VIEW, TreasuryPermissions::VIEW]);
        $this->actingAs($reader);
        $service = app(TreasuryReadReport::class);
        $r = $service->read($reader, '2026-11-01', '2026-11-01');
        $this->assertFalse($r['can_balance']);
        $this->assertNull($r['balances'][0]['balance']);
        $reader->givePermissionTo(TreasuryPermissions::BALANCES);
        $this->assertTrue($service->read($reader, '2026-11-01', '2026-11-01')['can_balance']);
        $reader->revokePermissionTo(TreasuryPermissions::BALANCES);
        $this->assertFalse($service->read($reader, '2026-11-01', '2026-11-01')['can_balance']);
        $central = TreasuryAccount::factory()->central()->create(['created_by' => $this->actor->id]);
        $this->expectException(HttpException::class);
        $service->read($reader, '2026-11-01', '2026-11-01', accountId: $central->id);
    }

    public function test_pos_sales_replay_and_closure_variance_match_authoritative_shift_service(): void
    {
        $this->prepareCheckout();
        $key = (string) Str::uuid7();
        $sale = $this->sale($key);
        $this->assertSame('100.0000', app(PosShiftService::class)->expectedClosingCash($this->actor, $this->shift->id));
        $live = app(PosReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->branch->id, sessionId: $this->shift->id);
        $this->assertSame('100.0000', $live['live_expected']);
        $closed = app(PosShiftService::class)->close($this->actor, $this->shift->id, ['counted_cash' => '99', 'tender_counts' => [$this->method->id => '100'], 'difference_reason' => 'One pound shortage'], (string) Str::uuid7());
        $this->assertSame($sale->id, $this->sale($key)->id);
        $this->projectPos();
        $r = $this->posReport();
        $this->assertSame(1, (int) $r['totals']['sale_count']);
        $this->assertSame('100.0000', $r['totals']['sale_total']);
        $this->assertSame($closed->expected_cash, $r['totals']['expected_cash']);
        $this->assertSame($closed->cash_difference, $r['totals']['variance']);
        $this->assertSame('-1.0000', $r['totals']['variance']);
        $this->assertSame(1, (int) $r['totals']['closed_count']);
    }

    public function test_posted_online_return_credit_refund_and_recovery_are_counted_once(): void
    {
        $this->prepareCheckout();
        $sale = $this->sale();
        $returns = app(SalesReturnService::class);
        $line = $sale->result_payload['return_lines'][0];
        $return = $returns->create($this->actor, ['sales_order_id' => $sale->sales_order_id, 'sales_invoice_id' => $sale->sales_invoice_id, 'return_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $line['sales_delivery_item_id'], 'sales_invoice_item_id' => $line['sales_invoice_item_id'], 'quantity' => '1', 'disposition' => 'restock']]], (string) Str::uuid7());
        $approver = User::factory()->create(['is_active' => true]);
        $approver->locations()->attach($this->branch);
        $approver->givePermissionTo(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        $return = app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $credits = app(SalesCreditNoteService::class);
        $credit = $credits->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $sale->sales_invoice_id, 'credit_date' => today()->toDateString(),
            'items' => $return->postedAllocations()->get()->map(fn ($a): array => ['sales_return_posted_allocation_id' => $a->id, 'quantity' => $a->quantity])->all()], (string) Str::uuid7());
        $credit = $credits->post($this->actor, $credit, $credits->revision($credit));
        $payment = Payment::where('pos_transaction_id', $sale->id)->firstOrFail();
        $key = (string) Str::uuid7();
        $data = ['payment_id' => $payment->id, 'amount' => '100', 'refund_date' => today()->toDateString(), 'reason' => 'Return cash', 'external_reference' => null];
        $refunds = app(CustomerRefunds::class);
        $refund = $refunds->refundCreditNote($this->actor, $credit, $data, $key);
        $refunds->refundCreditNote($this->actor, $credit, $data, $key);
        $this->projectPos();
        $this->projectTreasury();
        $r = $this->posReport();
        $sales = app(SalesReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->branch->id);
        $treasury = app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->branch->id);
        $this->assertSame('100.0000', $r['totals']['sale_total']);
        $this->assertSame('100.0000', $r['totals']['credit_total']);
        $this->assertSame('100.0000', $r['totals']['refund_total']);
        $this->assertSame('0.0000', $sales['collections']['total']);
        $this->assertSame('0.0000', $treasury['pos_collections']['total']);
        $this->assertSame(1, (int) $r['totals']['return_count']);
        $treasury = app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', accountId: $this->cash->id);
        $this->assertSame('100.0000', $treasury['totals']['external_in']);
        $this->assertSame('100.0000', $treasury['totals']['external_out']);
        app(PosShiftService::class)->close($this->actor, $this->shift->id, ['counted_cash' => '0', 'tender_counts' => [$this->method->id => '0']], (string) Str::uuid7());
        $this->travel(1)->days();
        $refunds->recover($this->actor, $refund, (string) Str::uuid7(), 'Wrong refund', 'RECOVER-1');
        $this->projectPos('2026-11-02');
        $this->projectTreasury(date: '2026-11-02');
        $this->assertSame('100.0000', $this->posReport('2026-11-02')['totals']['refund_recovery_total']);
    }

    public function test_non_cash_pos_receipt_needs_no_treasury_account_and_is_excluded_from_drawer_cash(): void
    {
        $this->prepareCheckout();
        app(PosShiftService::class)->close($this->actor, $this->shift->id, [
            'counted_cash' => '0',
            'tender_counts' => [],
        ], (string) Str::uuid7());
        $wallet = PaymentMethod::factory()->create([
            'type' => 'wallet',
            'requires_reference' => false,
            'available_in_pos' => true,
        ]);
        app(PosDeviceManagement::class)->configurePaymentMethod($this->actor, $this->shift->posDevice, (int) $wallet->id, null);
        $this->method = $wallet;
        $this->shift = app(PosShiftService::class)->open($this->actor, (int) $this->shift->pos_device_id, (int) $this->cash->id, '0', (string) Str::uuid7());

        $sale = $this->sale();
        $payment = Payment::where('pos_transaction_id', $sale->id)->firstOrFail();
        $this->assertNull($payment->treasury_account_id);
        $this->assertDatabaseMissing('treasury_operations', ['payment_id' => $payment->id]);
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertSame('0.0000', app(PosShiftService::class)->expectedClosingCash($this->actor, $this->shift->id));

        app(PosShiftService::class)->close($this->actor, $this->shift->id, [
            'counted_cash' => '0',
            'tender_counts' => [$wallet->id => '100'],
        ], (string) Str::uuid7());
        $sales = app(SalesReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->branch->id);
        $treasury = app(TreasuryReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->branch->id);
        $this->assertSame('100.0000', $sales['collections']['total']);
        $this->assertSame('0.0000', $sales['collections']['cash']);
        $this->assertSame('100.0000', $sales['collections']['non_cash']);
        $this->assertSame('100.0000', $sales['collections']['methods'][0]['amount']);
        $this->assertSame('100.0000', $treasury['pos_collections']['total']);
        $this->assertSame('0.0000', $treasury['pos_collections']['cash']);
        $this->assertSame('100.0000', $treasury['pos_collections']['non_cash']);
        DB::table('sales_invoices')->whereKey($sale->sales_invoice_id)->update(['cancelled_at' => now()]);
        $this->assertSame('0.0000', app(PosCollectionReport::class)->summarize(
            [(int) $this->branch->id],
            '2026-11-01',
            '2026-11-01',
        )['total']);

        $other = Location::create(['name' => 'Unauthorized collection branch', 'type' => 'branch', 'is_active' => true]);
        TreasuryAccount::factory()->create(['location_id' => $other->id, 'created_by' => $this->actor->id]);
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($reader, $this->branch->id);
        $reader->givePermissionTo([
            TreasuryReportingAccess::VIEW,
            TreasuryPermissions::VIEW,
            ReportingAccess::VIEW,
            SalesPermissions::INVOICES_VIEW,
            SalesPermissions::CREDITS_VIEW,
        ]);
        $this->actingAs($reader);
        try {
            app(TreasuryReadReport::class)->read($reader, '2026-11-01', '2026-11-01', $other->id);
            $this->fail('A user accessed POS collections for an unauthorized branch.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(SalesReport::class)->read($reader, '2026-11-01', '2026-11-01', $other->id);
            $this->fail('A user accessed sales collections for an unauthorized branch.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_offline_sale_sync_and_replay_project_one_authoritative_invoice(): void
    {
        $this->prepareCheckout();
        $quote = app(PosTerminalCatalog::class)->quote($this->actor, $this->branch->id, [$this->variant->id => ['quantity' => '1', 'discount_id' => null]]);
        $quote['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip(['variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'])), $quote['items']);
        $envelope = [
            'client_transaction_id' => (string) Str::uuid7(),
            'scope' => ['branch_id' => $this->branch->id, 'device_id' => $this->shift->pos_device_id, 'session_id' => $this->shift->id, 'cashier_id' => $this->actor->id],
            'sale' => ['items' => [['variant_id' => $this->variant->id, 'quantity' => '1']], 'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']], 'cash_received' => '100'],
            'quote' => $quote,
        ];
        $sync = app(PosOfflineSaleSyncService::class);
        $first = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);
        $retry = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);
        $this->assertSame('completed', $first['status']);
        $this->assertSame($first['transaction_uuid'], $retry['transaction_uuid']);
        $this->projectPos();
        $report = $this->posReport();
        $this->assertSame(1, (int) $report['totals']['sale_count']);
        $this->assertSame(1, (int) $report['totals']['completed']);
        $this->assertSame('100.0000', $report['totals']['sale_total']);
        $this->assertSame(1, Payment::where('pos_session_id', $this->shift->id)->count());
        $this->assertTrue(app(PosProjection::class)->isCurrent($this->branch->id, '2026-11-01'));
    }

    public function test_persisted_sync_status_and_missing_financial_evidence_are_explicit(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $shift = PosSession::factory()->create(['location_id' => $this->branch->id, 'pos_device_id' => $device->id, 'opened_by' => $this->actor->id, 'status' => 'closed', 'closed_at' => now(), 'expected_cash' => null, 'counted_cash' => null, 'cash_difference' => null]);
        foreach (['rejected', 'conflict', 'completed'] as $status) {
            PosTransaction::factory()->create(['pos_session_id' => $shift->id, 'sync_status' => $status, 'server_received_at' => now(), 'attempts' => 2, 'error_code' => $status === 'completed' ? null : 'SYNC_ERROR']);
        }
        $this->projectPos();
        $r = $this->posReport();
        $this->assertSame(1, (int) $r['totals']['rejected']);
        $this->assertSame(1, (int) $r['totals']['conflict']);
        $this->assertNull($r['totals']['sale_total']);
        $this->assertNull($r['totals']['expected_cash']);
        $this->assertNull($r['daily'][0]['net']);
        $this->assertArrayNotHasKey('offline_queue_count', $r);
        $this->assertArrayNotHasKey('request_payload', $r['requests'][0]);
        DB::table('pos_transactions')->where('pos_session_id', $shift->id)->where('sync_status', 'rejected')->update(['sync_status' => 'processing']);
        $recovery = new CashProjectionRecovery('pos');
        $recovery->scan(500);
        $this->assertFalse(app(PosProjection::class)->isCurrent($this->branch->id, '2026-11-01'));
        $this->projectPos();
        $this->assertSame(1, (int) $this->posReport()['totals']['processing']);
        $this->assertSame(0, (int) $this->posReport()['totals']['rejected']);
        $recovery->scan(500);
        $this->assertTrue(app(PosProjection::class)->isCurrent($this->branch->id, '2026-11-01'));
    }

    public function test_cashier_and_branch_scope_cannot_be_bypassed_with_filters_or_warm_cache(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $managerShift = PosSession::factory()->create(['location_id' => $this->branch->id, 'pos_device_id' => $device->id, 'opened_by' => $this->actor->id]);
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->branch);
        $readerDevice = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $own = PosSession::factory()->create(['location_id' => $this->branch->id, 'pos_device_id' => $readerDevice->id, 'opened_by' => $reader->id]);
        $this->projectPos();
        $this->posReport();
        app(LocationContext::class)->setFor($reader, $this->branch->id);
        $reader->givePermissionTo([PosReportingAccess::VIEW, PosPermissions::VIEW]);
        $this->actingAs($reader);
        $service = app(PosReadReport::class);
        $r = $service->read($reader, '2026-11-01', '2026-11-01');
        $this->assertSame([$own->id], array_column($r['rows'], 'session_id'));
        try {
            $service->read($reader, '2026-11-01', '2026-11-01', cashierId: $this->actor->id);
            $this->fail('Cashier filter escaped owner scope.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $other = Location::create(['name' => 'Other POS branch', 'type' => 'branch', 'is_active' => true]);
        $this->expectException(HttpException::class);
        $service->read($reader, '2026-11-01', '2026-11-01', $other->id);
    }

    public function test_redis_projection_epoch_is_atomic_and_unrelated_accounts_stay_cached(): void
    {
        config(['read_cache.store' => 'redis', 'database.redis.client' => 'predis']);
        app('redis')->connection('cache')->ping();
        $this->operation('opening_balance', '10');
        $this->projectTreasury();
        $cache = app(ReadModelCache::class);
        $calls = 0;
        $read = function () use (&$calls): array {
            return ['calls' => ++$calls];
        };
        $deps = [DailyProjectionState::epoch('treasury', $this->cash->id, '2026-11-01')];
        $other = [DailyProjectionState::epoch('treasury', 999999, '2026-11-01')];
        $this->assertSame(['calls' => 1], $cache->remember('reporting_treasury', ['probe'], $read, dependencies: $deps));
        $this->assertSame(['calls' => 2], $cache->remember('reporting_treasury', ['other'], $read, dependencies: $other));
        $this->operation('cash_deposit', '1.0001');
        DB::beginTransaction();
        app(TreasuryProjection::class)->project($this->cash->id, '2026-11-01');
        DB::rollBack();
        $this->assertSame(['calls' => 1], $cache->remember('reporting_treasury', ['probe'], $read, dependencies: $deps));
        app(TreasuryProjection::class)->project($this->cash->id, '2026-11-01');
        $this->assertSame(['calls' => 3], $cache->remember('reporting_treasury', ['probe'], $read, dependencies: $deps));
        $this->assertSame(['calls' => 2], $cache->remember('reporting_treasury', ['other'], $read, dependencies: $other));
    }

    public function test_backfill_reconciliation_recovery_retries_and_pages(): void
    {
        $this->operation('opening_balance', '10');
        $this->travel(1)->days();
        $options = ['domain' => 'treasury', '--account' => $this->cash->id, '--from' => '2026-11-01', '--until' => '2026-11-02'];
        $this->artisan('reporting:cash-backfill', $options + ['--check' => true])->assertFailed();
        $this->artisan('reporting:cash-backfill', $options)->assertSuccessful();
        $this->artisan('reporting:cash-backfill', $options + ['--check' => true])->assertSuccessful();
        $this->artisan('reporting:cash-backfill', array_replace($options, ['--from' => '2026-09-01']))->assertFailed();
        $p = app(TreasuryProjection::class);
        $p->requestIfChanged($this->cash->id, '2026-11-01');
        $row = DB::table('reporting_treasury_partitions')->where('account_id', $this->cash->id)->where('report_date', '2026-11-01')->first();
        $this->assertSame($row->revision, $row->projected_revision);
        DB::table('reporting_treasury_daily')->where('account_id', $this->cash->id)->update(['amount' => '99']);
        $this->artisan('reporting:cash-backfill', $options + ['--check' => true])->assertFailed();
        Bus::fake([ProjectCashDay::class]);
        $recovery = new CashProjectionRecovery('treasury');
        for ($i = 0; $i < 3; $i++) {
            $recovery->scan(500);
        }$recovery->dispatch(500);
        Bus::assertDispatched(ProjectCashDay::class, fn ($job): bool => $job->scopeId === $this->cash->id && $job->domain === 'treasury' && $job->queue === 'reporting');
        $job = new ProjectCashDay('treasury', $this->cash->id, '2026-11-01');
        $job->handle();
        $job->handle();
        $this->assertTrue($p->matches($this->cash->id, '2026-11-01'));
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        PosSession::factory()->create(['location_id' => $this->branch->id, 'pos_device_id' => $device->id, 'opened_by' => $this->actor->id]);
        $pos = ['domain' => 'pos', '--location' => $this->branch->id, '--from' => '2026-11-01', '--until' => '2026-11-02'];
        $this->artisan('reporting:cash-backfill', $pos)->assertSuccessful();
        $this->artisan('reporting:cash-backfill', $pos + ['--check' => true])->assertSuccessful();
        Livewire::test(TreasuryReports::class)->set('from', '2026-11-01')->set('until', '2026-11-02')->set('accountId', $this->cash->id)->call('applyFilters')->assertSuccessful();
        Livewire::test(PosReports::class)->set('from', '2026-11-01')->set('until', '2026-11-02')->set('locationId', $this->branch->id)->call('applyFilters')->assertSuccessful()->assertSee('Browser-only offline queue contents are unavailable.');
        Livewire::withoutLazyLoading()->test(DailyCashChart::class, ['domain' => 'pos', 'from' => '2026-11-01', 'until' => '2026-11-02', 'locationId' => $this->branch->id])->assertSuccessful();
    }
}
