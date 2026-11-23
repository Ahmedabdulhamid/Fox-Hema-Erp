<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Reporting\Filament\Pages\PurchasingReports;
use App\Modules\Reporting\Jobs\ProjectPurchasingDay;
use App\Modules\Reporting\Services\PurchasingProjection;
use App\Modules\Reporting\Services\PurchasingProjectionRecovery;
use App\Modules\Reporting\Services\PurchasingReadReport;
use App\Modules\Reporting\Services\PurchasingReportingAccess;
use App\Modules\Reporting\Services\PurchasingReportingEvidence;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $location;

    private Supplier $supplier;

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
            throw new \LogicException('Purchasing reports require a disposable test database.');
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
        $this->location = Location::create(['name' => 'Purchasing '.Str::uuid(), 'type' => 'warehouse', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
        $this->supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['name' => 'Reporting shoes', 'status' => 'active', 'is_active' => true,
            'category_id' => Category::factory()->create()->id, 'brand_id' => Brand::factory()->create(['name' => 'Reporting brand '.Str::uuid()])->id]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id,
            'color_id' => (Color::where('name', 'Ocean')->first() ?? Color::factory()->create(['name' => 'Ocean', 'hex_code' => '#125577']))->id,
            'size_id' => (Size::where('name', '42')->first() ?? Size::factory()->create(['name' => '42']))->id]);
        InventoryValuationSetting::create(['category_id' => $product->category_id, 'costing_method' => 'fifo']);
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'purchasing-report:'.Str::uuid()]);
        Bus::fake([ProjectPurchasingDay::class]);
    }

    private function order(): PurchaseOrder
    {
        $service = app(PurchaseOrderService::class);
        $order = $service->create($this->actor, [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => '2026-11-01',
            'items' => [app(PurchaseOrderProductSelection::class)->formState($this->variant) + [
                'variant_id' => $this->variant->id, 'quantity' => '10', 'unit_price' => '5',
            ]],
        ], (string) Str::uuid7());

        return $service->confirm($this->actor, $order, $service->revision($order));
    }

    private function receipt(PurchaseOrder $order, string $quantity = '4'): PurchaseReceipt
    {
        $service = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->where('status', 'draft')->firstOrFail();
        $receipt = $service->update($this->actor, $receipt, ['items' => $receipt->items->map(fn ($line): array => [
            'id' => $line->id, 'quantity' => $quantity, 'unit_cost' => '5',
        ])->all()], $service->revision($receipt));

        return $service->post($this->actor, $receipt, $service->revision($receipt));
    }

    private function bill(PurchaseOrder $order, PurchaseReceipt $receipt, string $quantity = '2'): VendorBill
    {
        $service = app(VendorBillService::class);
        $bill = $service->create($this->actor, [
            'supplier_id' => $this->supplier->id, 'purchase_order_id' => $order->id, 'bill_number' => 'TEST-'.Str::uuid7(),
            'bill_date' => '2026-11-01', 'settlement_terms' => 'credit', 'planned_immediate_amount' => '0', 'due_date' => '2026-12-01',
            'items' => [['variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => '5',
                'allocations' => [['purchase_receipt_item_id' => $receipt->items()->firstOrFail()->id, 'quantity' => $quantity]]]],
        ]);

        return $service->post($this->actor, $bill, $service->revision($bill));
    }

    private function payment(string $amount = '7'): VendorPayment
    {
        $account = TreasuryAccount::factory()->create(['location_id' => $this->location->id, 'created_by' => $this->actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, [
            'type' => 'opening_balance', 'account_id' => $account->id, 'amount' => '100', 'effective_date' => '2026-11-01', 'reason' => 'Disposable reporting fixture',
        ], (string) Str::uuid7()));
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        $payments = app(VendorPaymentService::class);

        return $payments->post($this->actor, $payments->createDraft($this->actor, [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'payment_method_id' => $method->id,
            'treasury_account_id' => $account->id, 'payment_date' => '2026-11-01', 'amount' => $amount, 'currency' => 'EGP',
        ], (string) Str::uuid7()));
    }

    private function project(string $date = '2026-11-01'): void
    {
        $projection = app(PurchasingProjection::class);
        $projection->request($this->location->id, $date);
        $projection->project($this->location->id, $date);
    }

    private function report(): array
    {
        return app(PurchasingReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->location->id, 'EGP', $this->supplier->id);
    }

    public function test_partial_receipts_and_bills_remain_distinct_and_projection_replay_is_idempotent(): void
    {
        $order = $this->order();
        $receipt = $this->receipt($order);
        $this->bill($order, $receipt);
        $this->project();
        $report = $this->report();
        $this->assertEquals(50, $report['totals']['order']['amount']);
        $this->assertEquals(4, $report['totals']['receipt_line']['quantity']);
        $this->assertEquals(20, $report['totals']['receipt_line']['amount']);
        $this->assertEquals(2, $report['totals']['bill_line']['quantity']);
        $this->assertEquals(10, $report['totals']['bill']['amount']);
        $this->assertEquals(6, $report['totals']['order_line']['remaining']);
        $this->assertEquals(1, $report['totals']['receipt']['partial_count']);
        $this->assertSame($this->variant->product->brand->name, $report['variants'][0]['brand_name']);
        $this->assertSame('#125577', $report['variants'][0]['color_hex']);
        $this->assertFalse(app(PurchasingProjection::class)->project($this->location->id, '2026-11-01'));
        $count = DB::table('reporting_purchasing_daily')->count();
        $this->project();
        $this->assertSame($count, DB::table('reporting_purchasing_daily')->count());
        $this->assertTrue(app(PurchasingProjection::class)->isCurrent($this->location->id, '2026-11-01'));
    }

    public function test_allocations_settle_bills_without_adding_payment_amount_and_advances_match_authority(): void
    {
        $order = $this->order();
        $bill = $this->bill($order, $this->receipt($order));
        $payment = $this->payment();
        app(VendorPaymentAllocationService::class)->allocate($this->actor, $payment, $bill, '5', (string) Str::uuid7());
        $this->project();
        $report = $this->report();
        $this->assertEquals(7, $report['totals']['payment']['amount']);
        $this->assertEquals(5, $report['balances'][0]['paid']);
        $this->assertEquals(5, $report['balances'][0]['outstanding']);
        $this->assertEquals(2, $report['balances'][0]['advances']);
        $authoritative = app(SupplierFinancialBalances::class)->supplier($this->actor, $this->supplier->id);
        $this->assertSame($authoritative['net_supplier_balance'], $report['balances'][0]['net_balance']);
    }

    public function test_full_receipt_batch_and_cancelled_draft_orders_do_not_inflate_outstanding_quantities(): void
    {
        $order = $this->order();
        $this->receipt($order, '10');
        $draft = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'created_by' => $this->actor->id,
            'status' => 'cancelled', 'order_date' => '2026-11-01', 'total_amount' => '100',
        ]);
        $this->project();
        $report = $this->report();
        $this->assertEquals(0, $report['totals']['order_line']['remaining']);
        $this->assertEquals(1, $report['totals']['receipt']['complete_count']);
        $this->assertContains('cancelled', array_column($report['rows'], 'status'));
    }

    public function test_recovery_retains_original_payment_activity_and_records_reversal_on_its_own_day(): void
    {
        $payment = $this->payment();
        $this->project();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));
        app(VendorPaymentService::class)->recover($this->actor, $payment, (string) Str::uuid7(), 'Supplier returned funds', 'RECOVERY-VERIFIED');
        $this->project();
        $this->project('2026-11-02');
        $report = $this->report();
        $this->assertEquals(7, $report['totals']['payment']['amount']);
        $this->assertEquals(0, $report['totals']['recovery']['amount']);
        $dayTwo = app(PurchasingReadReport::class)->read($this->actor, '2026-11-02', '2026-11-02', $this->location->id);
        $this->assertEquals(7, $dayTwo['totals']['recovery']['amount']);
        $this->assertCount(0, $dayTwo['balances']);
    }

    public function test_transactions_roll_back_dirty_revisions_and_cache_remains_consistent(): void
    {
        $order = $this->order();
        $this->project();
        $this->report();
        $revision = DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->value('revision');
        DB::beginTransaction();
        $order->forceFill(['status' => 'cancelled'])->save();
        $this->assertGreaterThan($revision, DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->value('revision'));
        DB::rollBack();
        $this->assertEquals($revision, DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->value('revision'));
        $this->assertEquals(50, $this->report()['totals']['order']['amount']);
    }

    public function test_committed_receipt_invalidates_cached_report_and_dispatches_only_after_commit(): void
    {
        $order = $this->order();
        $this->project();
        $this->assertEquals(0, $this->report()['totals']['receipt_line']['quantity']);
        $this->receipt($order);
        $this->assertNull($this->report()['totals']['receipt_line']['quantity']);
        Bus::assertDispatched(ProjectPurchasingDay::class);
        $this->project();
        $this->assertEquals(4, $this->report()['totals']['receipt_line']['quantity']);
    }

    public function test_reconciliation_is_read_only_backfill_is_bounded_and_recovery_repairs_missed_hooks(): void
    {
        $order = $this->order();
        $this->project();
        $arguments = ['--location' => $this->location->id, '--from' => '2026-11-01', '--until' => '2026-11-01'];
        $revision = DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->value('revision');
        $this->artisan('reporting:purchasing-backfill', $arguments + ['--check' => true])->assertSuccessful();
        $this->assertEquals($revision, DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->value('revision'));
        DB::table('purchase_orders')->where('id', $order->id)->update(['total_amount' => '51']);
        $this->artisan('reporting:purchasing-backfill', $arguments + ['--check' => true])->assertFailed();
        DB::table('reporting_purchasing_checkpoints')->updateOrInsert(['source' => 'orders'], ['last_id' => $order->id - 1]);
        app(PurchasingProjectionRecovery::class)->scan(1);
        $this->assertTrue(DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->whereColumn('revision', '>', 'projected_revision')->exists());
        $this->artisan('reporting:purchasing-backfill', $arguments)->assertSuccessful();
        $this->assertEquals(51, $this->report()['totals']['order']['amount']);
        $this->artisan('reporting:purchasing-backfill', ['--location' => $this->location->id, '--from' => '2026-01-01', '--until' => '2026-03-01'])->assertFailed();
        $this->artisan('reporting:purchasing-recover', ['--limit' => 501])->assertFailed();
    }

    public function test_branch_and_financial_permissions_are_required_even_after_cache_warming(): void
    {
        $this->order();
        $this->project();
        $this->report();
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->location);
        app(LocationContext::class)->setFor($reader, $this->location->id);
        $reader->givePermissionTo([PurchasingReportingAccess::VIEW, PurchasingPermissions::VIEW, PurchasingPermissions::ORDERS_VIEW,
            PurchasingPermissions::RECEIPTS_VIEW, PurchasingPermissions::BILLS_VIEW, PurchasingPermissions::PAYMENTS_VIEW]);
        $this->actingAs($reader);
        $report = app(PurchasingReadReport::class)->read($reader, '2026-11-01', '2026-11-01');
        $this->assertEquals(50, $report['totals']['order']['amount']);
        $other = Location::create(['name' => 'Forbidden', 'type' => 'branch', 'is_active' => true]);
        try {
            app(PurchasingReadReport::class)->read($reader, '2026-11-01', '2026-11-01', $other->id);
            $this->fail('Cross-location reporting was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $reader->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        $this->assertFalse(app(PurchasingReportingAccess::class)->allows($reader));
    }

    public function test_missing_receipt_posting_evidence_is_unavailable_instead_of_zero(): void
    {
        $receipt = $this->receipt($this->order());
        DB::table('purchase_receipts')->where('id', $receipt->id)->update(['posted_at' => null]);
        $this->project();
        $this->assertNull($this->report()['totals']['receipt_line']['quantity']);
    }

    public function test_lazy_bilingual_ui_reuses_variant_swatches_and_contains_no_untranslated_keys(): void
    {
        $this->receipt($this->order());
        $this->project();
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $component = Livewire::test(PurchasingReports::class)->assertSet('isLoaded', false)->call('loadReport')->assertSuccessful()
                ->assertSee(__('reporting.pages.purchasing.title'))->assertSee('Reporting brand')->assertSee('Ocean')->assertSee('42');
            $html = $component->html();
            $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);
            $this->assertStringNotContainsString('purchasing_reporting.', $html);
            $this->assertStringNotContainsString($this->variant->sku, $html);
        }
    }

    public function test_releasing_an_allocation_reopens_the_bill_without_creating_another_payment(): void
    {
        $order = $this->order();
        $bill = $this->bill($order, $this->receipt($order));
        $payment = $this->payment();
        $allocations = app(VendorPaymentAllocationService::class);
        $allocation = $allocations->allocate($this->actor, $payment, $bill, '5', (string) Str::uuid7());
        $this->project();
        $this->assertEquals(5, $this->report()['balances'][0]['outstanding']);
        $allocations->release($this->actor, $allocation, (string) Str::uuid7(), 'Release incorrect allocation');
        $this->assertNull($this->report()['balances'][0]['outstanding']);
        $this->project();
        $report = $this->report();
        $this->assertEquals(10, $report['balances'][0]['outstanding']);
        $this->assertEquals(7, $report['balances'][0]['advances']);
        $this->assertEquals(7, $report['totals']['payment']['amount']);
    }

    public function test_unreconciled_legacy_finances_are_unavailable_and_currency_is_not_guessed(): void
    {
        $this->order();
        $legacy = VendorPayment::factory()->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->actor->id]);
        DB::table('vendor_payments')->where('id', $legacy->id)->update(['status' => 'posted', 'posted_at' => now()]);
        $this->project();
        $report = $this->report();
        $this->assertTrue($report['balance_missing']);
        $this->assertNull($report['balances'][0]['net_balance']);
        $this->assertNull($report['totals']['payment']['amount']);
        $this->expectException(ValidationException::class);
        app(PurchasingReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->location->id, 'USD');
    }

    public function test_current_balances_are_independent_of_activity_dates_and_supplier_filters_do_not_mix_balances(): void
    {
        $this->payment();
        $this->project();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));
        $this->project('2026-11-02');
        $report = app(PurchasingReadReport::class)->read($this->actor, '2026-11-02', '2026-11-02', $this->location->id, 'EGP', $this->supplier->id);
        $this->assertEquals(0, $report['totals']['payment']['amount']);
        $this->assertEquals(7, $report['balances'][0]['advances']);
        $other = Supplier::factory()->create();
        $report = app(PurchasingReadReport::class)->read($this->actor, '2026-11-02', '2026-11-02', $this->location->id, 'EGP', $other->id);
        $this->assertSame([], $report['balances']);
    }

    public function test_failed_projection_rolls_back_and_expired_dispatch_leases_can_be_recovered(): void
    {
        $this->order();
        $this->project();
        $projection = app(PurchasingProjection::class);
        $projection->request($this->location->id, '2026-11-01');
        $count = DB::table('reporting_purchasing_daily')->where('location_id', $this->location->id)->count();
        $this->mock(PurchasingReportingEvidence::class)
            ->shouldReceive('daily')->once()->andThrow(new \RuntimeException('Interrupted projection'));
        try {
            $projection->project($this->location->id, '2026-11-01');
            $this->fail('Expected interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Interrupted projection', $exception->getMessage());
        }
        app()->forgetInstance(PurchasingReportingEvidence::class);
        $this->assertSame($count, DB::table('reporting_purchasing_daily')->where('location_id', $this->location->id)->count());
        $partition = DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id);
        $this->assertTrue((clone $partition)->whereColumn('revision', '>', 'projected_revision')->exists());
        DB::table('reporting_purchasing_partitions')->whereColumn('revision', '>', 'projected_revision')->update(['dispatched_at' => now()]);
        $recovery = app(PurchasingProjectionRecovery::class);
        $this->assertSame(0, $recovery->dispatch(1));
        $partition->update(['dispatched_at' => now()->subMinutes(6)]);
        $this->assertSame(1, $recovery->dispatch(1));
        (new ProjectPurchasingDay($this->location->id, '2026-11-01'))->handle();
        $this->assertTrue($projection->isCurrent($this->location->id, '2026-11-01'));
    }

    public function test_variant_pagination_uses_grouped_joins_without_queries_per_row(): void
    {
        $this->project();
        config(['read_cache.enabled' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->report();
        $emptyQueryCount = count(DB::getQueryLog());
        $variants = ProductVariant::factory()->count(26)->create(['product_id' => $this->variant->product_id]);
        DB::table('reporting_purchasing_daily')->insert($variants->map(fn (ProductVariant $variant): array => [
            'location_id' => $this->location->id, 'report_date' => '2026-11-01', 'supplier_id' => $this->supplier->id,
            'currency' => 'EGP', 'event' => 'receipt_line', 'status' => 'posted', 'variant_id' => $variant->id,
            'quantity' => '1.0000', 'amount' => '5.0000', 'projected_at' => now(),
        ])->all());
        DB::flushQueryLog();
        $first = $this->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(26, $first['variant_total']);
        $this->assertCount(25, $first['variants']);
        $this->assertLessThanOrEqual($emptyQueryCount + 2, count($queries));
        $this->assertLessThanOrEqual(2, count(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'product_variants'))));
        $second = app(PurchasingReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->location->id, 'EGP', $this->supplier->id, page: 2);
        $this->assertCount(1, $second['variants']);
        $this->assertNotContains($second['variants'][0]['variant_id'], array_column($first['variants'], 'variant_id'));
    }

    public function test_recovery_projects_current_day_for_active_locations_without_purchasing_activity(): void
    {
        $this->assertFalse(DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)->exists());
        app(PurchasingProjectionRecovery::class)->scan(500);
        $this->assertTrue(DB::table('reporting_purchasing_partitions')->where('location_id', $this->location->id)
            ->where('report_date', today()->toDateString())->whereColumn('revision', '>', 'projected_revision')->exists());
        (new ProjectPurchasingDay($this->location->id, today()->toDateString()))->handle();
        $report = $this->report();
        $this->assertEquals(0, $report['totals']['order']['amount']);
        $this->assertSame(1, $report['freshness']['projected_days']);
        $this->assertSame(0, $report['freshness']['pending_days']);
    }

    public function test_optional_real_redis_cache_hits_and_committed_invalidation_use_only_owned_keys(): void
    {
        if (getenv('PURCHASING_REDIS_CHECK') !== '1') {
            $this->markTestSkipped('Set PURCHASING_REDIS_CHECK=1 when local Redis is available.');
        }
        $prefix = 'purchasing-rollout-redis:'.Str::uuid();
        config(['read_cache.store' => 'redis', 'read_cache.prefix' => $prefix]);
        $keys = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use ($prefix, &$keys): void {
            if ($event->storeName === 'redis' && str_starts_with($event->key, $prefix.':')) {
                $keys[] = $event->key;
            }
        });
        try {
            $order = $this->order();
            $this->project();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->report();
            $coldQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'reporting_purchasing_daily'));
            $this->assertNotEmpty($coldQueries);
            $this->assertNotEmpty($keys);
            DB::flushQueryLog();
            $this->assertEquals(0, $this->report()['totals']['receipt_line']['quantity']);
            $warmQueries = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'reporting_purchasing_daily'));
            $this->assertSame([], array_values($warmQueries));
            DB::disableQueryLog();
            $this->receipt($order);
            $this->assertNull($this->report()['totals']['receipt_line']['quantity']);
            $this->project();
            $this->assertEquals(4, $this->report()['totals']['receipt_line']['quantity']);
        } finally {
            DB::disableQueryLog();
            foreach (array_unique($keys) as $key) {
                Cache::store('redis')->forget($key);
            }
        }
    }
}
