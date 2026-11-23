<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\FifoLayerValue;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Filament\VariantInventoryValuationPanel;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Products\Services\VariantInventoryValuation;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PhaseTwoFinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Phase Two Branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...SalesPermissions::all(),
            ...TreasuryPermissions::all(), ...InventoryPermissions::all(), ...ProductPermissions::all()]);
        $this->actingAs($this->actor);
    }

    private function customer(?Location $branch = null): Customer
    {
        return app(CustomerAccounts::class)->save($this->actor, ['location_id' => ($branch ?? $this->branch)->id, 'name' => 'Phase Two Customer']);
    }

    private function invoice(?Customer $customer = null, ?Location $branch = null): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => ($branch ?? $this->branch)->id,
            'created_by' => $this->actor->id, 'subtotal' => '100.0001', 'total_amount' => '100.0001']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id,
        ]);
        $invoice->refresh();
        if ($customer !== null) {
            app(CustomerAccounts::class)->linkInvoice($this->actor, $customer, $invoice, [], (string) Str::uuid7());
        }

        return $invoice;
    }

    private function credit(SalesInvoice $invoice, string $amount = '30.0001', bool $cancelled = false): int
    {
        return DB::table('sales_credit_notes')->insertGetId(['uuid' => (string) Str::uuid7(),
            'credit_note_number' => 'PHASE-TWO-'.Str::uuid7(), 'sales_invoice_id' => $invoice->id,
            'location_id' => $invoice->location_id, 'credit_date' => now()->toDateString(),
            'status' => 'posted', 'subtotal' => $amount, 'total_amount' => $amount, 'created_by' => $this->actor->id,
            'posted_at' => now(), 'posted_by' => $this->actor->id, 'cancelled_at' => $cancelled ? now() : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function payment(Customer $customer, string $amount): Payment
    {
        $service = app(CustomerPayments::class);

        return $service->post($this->actor, $service->createDraft($this->actor, [
            'customer_id' => $customer->id, 'payment_method_id' => PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false])->id,
            'treasury_account_id' => TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id])->id,
            'amount' => $amount, 'payment_date' => now()->toDateString(),
        ], (string) Str::uuid7()));
    }

    /** @return array<string, array{int}> */
    public static function invoiceCounts(): array
    {
        return ['one' => [1], 'six' => [6], 'fifty' => [50]];
    }

    #[DataProvider('invoiceCounts')]
    public function test_customer_query_count_is_bounded_and_exact(int $count): void
    {
        $customer = $this->customer();
        for ($index = 0; $index < $count; $index++) {
            $this->invoice($customer);
        }
        $this->invoice($this->customer());
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $balances = app(CustomerFinancials::class)->customer($this->actor, $customer);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $aggregates = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'sum('));
        fwrite(STDOUT, PHP_EOL.'FH-05 invoices='.$count.' total_queries='.count($queries).' aggregate_queries='.count($aggregates).PHP_EOL);
        $this->assertSame((string) BigDecimal::of('100.0001')->multipliedBy($count)->toScale(4), $balances['outstanding']);
        $this->assertSame('0.0000', $balances['advance']);
        $this->assertLessThanOrEqual(2, count($aggregates));
        $this->assertLessThanOrEqual(80, count($queries));
    }

    public function test_grouped_balances_match_invoice_and_advance_rules_through_credit_refund_and_recovery(): void
    {
        $customer = $this->customer();
        $partial = $this->invoice($customer);
        $full = $this->invoice($customer);
        $creditInvoice = $this->invoice($customer);
        $cancelled = $this->invoice($customer);
        DB::table('sales_invoices')->where('id', $cancelled->id)->update(['cancelled_at' => now()]);
        $draft = $this->invoice($customer);
        DB::table('sales_invoices')->where('id', $draft->id)->update(['status' => 'draft', 'posted_at' => null, 'posted_by' => null]);
        $payment = $this->payment($customer, '400.0004');
        $allocations = app(CustomerAllocations::class);
        $allocations->allocate($this->actor, $payment, $partial, '40.0000', (string) Str::uuid7());
        $allocations->allocate($this->actor, $payment, $full, '100.0001', (string) Str::uuid7());
        $allocation = $allocations->allocate($this->actor, $payment, $creditInvoice, '100.0001', (string) Str::uuid7());
        $this->credit($creditInvoice);
        $this->credit($partial, '5.0000', true);
        $draftCredit = $this->credit($partial, '7.0000');
        DB::table('sales_credit_notes')->where('id', $draftCredit)->update(['status' => 'draft', 'posted_at' => null, 'posted_by' => null]);
        $financials = app(CustomerFinancials::class);
        $assertBalances = function (string $advance) use ($financials, $customer, $partial, $full, $creditInvoice, $payment): void {
            $outstanding = BigDecimal::of('0.0000');
            $expectedAdvance = BigDecimal::of($financials->available($payment->fresh(), $this->actor));
            foreach ([$partial, $full, $creditInvoice] as $invoice) {
                $balance = $financials->invoice($this->actor, $invoice);
                $outstanding = $outstanding->plus($balance['outstanding']);
                $expectedAdvance = $expectedAdvance->plus($balance['excess_allocation']);
            }
            $this->assertSame('60.0001', (string) $outstanding);
            $this->assertSame($advance, (string) $expectedAdvance);
            $this->assertSame(['outstanding' => (string) $outstanding, 'advance' => $advance,
                'net_balance' => (string) $outstanding->minus($advance)], $financials->customer($this->actor, $customer));
        };
        $assertBalances('190.0003');
        $allocations->release($this->actor, $allocation, (string) Str::uuid7(), 'Credit adjustment');
        $allocations->allocate($this->actor, $payment, $creditInvoice, '70.0000', (string) Str::uuid7());
        $assertBalances('190.0003');
        $refunds = app(CustomerRefunds::class);
        $refund = $refunds->post($this->actor, $refunds->createDraft($this->actor, ['payment_id' => $payment->id,
            'amount' => '30.0001', 'refund_date' => now()->toDateString(), 'reason' => 'Unused advance'], (string) Str::uuid7()));
        $assertBalances('160.0002');
        $refunds->recover($this->actor, $refund, (string) Str::uuid7(), 'Funds returned', 'PHASE-TWO-RECOVERY');
        $assertBalances('190.0003');
    }

    public function test_history_filters_linked_events_orders_and_paginates_without_duplicates(): void
    {
        $this->travelTo(now()->startOfSecond());
        $customer = $this->customer();
        $first = $this->invoice($customer);
        $this->travel(1)->days();
        $secondCustomer = $this->customer();
        $second = $this->invoice($secondCustomer);
        $this->customer();
        $unlinked = $this->invoice();
        $this->credit($unlinked);
        $this->travel(1)->days();
        $creditId = $this->credit($first);
        $this->credit($second, '5.0000', true);
        $financials = app(CustomerFinancials::class);
        $this->assertSame(['I'.$first->id], $financials->history($this->actor, $customer->id, null, null, '', 'invoice')->pluck('event_key')->all());
        $this->assertSame(['I'.$first->id, 'I'.$second->id], $financials->history($this->actor, null, null, null, '', 'invoice')->pluck('event_key')->all());
        $this->assertSame(['C'.$creditId], $financials->history($this->actor, null, null, null, '', 'credit')->pluck('event_key')->all());
        $rows = $financials->history($this->actor, null, null, null, '', '')->get();
        $this->assertSame(['I'.$first->id, 'I'.$second->id, 'C'.$creditId], $rows->pluck('event_key')->all());
        $this->assertSame('70.0000', (string) $rows->last()->running_balance);
        $page = $financials->history($this->actor, null, null, null, '', '')->paginate(1, ['*'], 'page', 2);
        $this->assertSame(3, $page->total());
        $this->assertSame('I'.$second->id, $page->items()[0]->event_key);
        $this->assertSame(1, $financials->history($this->actor, null, now()->toDateString(), now()->toDateString(), '', '')->count());
        $this->assertSame(1, $financials->history($this->actor, null, null, null, $second->invoice_number, 'invoice')->count());
    }

    public function test_payments_view_all_does_not_expand_sales_history_or_customer_balance_authority(): void
    {
        $own = $this->customer();
        $invoice = $this->invoice($own);
        $this->credit($invoice);
        $foreignBranch = Location::create(['name' => 'Foreign Phase Two Branch', 'type' => 'branch', 'is_active' => true]);
        $originalActor = $this->actor;
        $this->actor = $this->provisionTestSuperAdmin();
        $foreignCustomer = $this->customer($foreignBranch);
        $foreignInvoice = $this->invoice($foreignCustomer, $foreignBranch);
        $this->credit($foreignInvoice);
        $this->actor = $originalActor;
        $financials = app(CustomerFinancials::class);
        $this->assertSame(2, $financials->history($this->actor, null, null, null, '', '')->count());
        $admin = $this->provisionTestSuperAdmin();
        $this->assertSame(4, $financials->history($admin, null, null, null, '', '')->count());
        try {
            $financials->customer($this->actor, $foreignCustomer);
            $this->fail('Foreign Sales invoice must remain unauthorized with Payments view-all.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->actor->revokePermissionTo(PaymentsPermissions::VIEW_ALL_LOCATIONS);
        $this->assertSame(2, $financials->history($this->actor, null, null, null, '', '')->count());
        $this->expectException(HttpException::class);
        $financials->history($this->actor, $foreignCustomer->id, null, null, '', 'invoice');
    }

    public function test_missing_sales_permission_omits_all_customer_sales_events_and_denies_selected_statement(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        $this->credit($invoice);
        $payment = $this->payment($customer, '10.0001');
        $this->actor->revokePermissionTo(SalesPermissions::INVOICES_VIEW);
        $financials = app(CustomerFinancials::class);
        $this->assertSame(['P'.$payment->id], $financials->history($this->actor, null, null, null, '', '')->pluck('event_key')->all());
        $this->assertSame(0, $financials->history($this->actor, null, null, null, '', 'invoice')->count());
        $this->assertSame(0, $financials->history($this->actor, null, null, null, '', 'credit')->count());
        $this->expectException(HttpException::class);
        $financials->history($this->actor, $customer->id, null, null, '', '');
    }

    private function layer(ProductVariant $variant, string $remaining, ?string $initial = '100.0000', ?Location $branch = null): FifoLayer
    {
        $movement = StockMovement::create(['variant_id' => $variant->id, 'location_id' => ($branch ?? $this->branch)->id,
            'type' => 'purchase', 'quantity' => '300', 'unit_cost' => '0.3333']);
        $layer = FifoLayer::factory()->create(['stock_movement_id' => $movement->id, 'variant_id' => $variant->id,
            'quantity' => '300', 'remaining_qty' => $remaining, 'unit_cost' => '0.333333']);
        if ($initial !== null) {
            DB::table('fifo_layers')->where('id', $layer->id)->update(['initial_value' => $initial, 'available_at' => now(),
                'remaining_value' => (string) BigDecimal::of($initial)->multipliedBy($remaining)->dividedBy('300', 4, RoundingMode::HalfUp)]);
        }

        return $layer->fresh();
    }

    public function test_fifo_multiple_exact_legacy_and_consumed_layers_reconcile_without_writes(): void
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => Category::factory()])->id]);
        InventoryValuationSetting::create(['category_id' => $variant->product->category_id, 'costing_method' => 'fifo']);
        $this->layer($variant, '149');
        $this->layer($variant, '3', '200.0000');
        $this->layer($variant, '10', null);
        $this->layer($variant, '0');
        $foreign = Location::create(['name' => 'Other FIFO Branch', 'type' => 'branch', 'is_active' => true]);
        $this->layer($variant, '300', '100.0000', $foreign);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '162', 'quantity_available' => '162', 'quantity_reserved' => '0']);
        $state = InventoryCostState::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'on_hand_qty' => '162', 'avg_cost' => '0.339506', 'total_value' => '55.0000']);
        $before = DB::table('fifo_layers')->orderBy('id')->get()->toJson();
        $valuation = app(VariantInventoryValuation::class)->read($this->actor, $variant, $this->branch->id);
        $this->assertSame('55.0000', $valuation['fifo_value']);
        $this->assertSame('162.0000', $valuation['fifo_quantity']);
        $this->assertSame(3, $valuation['layers']->total());
        $this->assertFalse($valuation['fifo_mismatch']);
        $this->assertSame($before, DB::table('fifo_layers')->orderBy('id')->get()->toJson());
        $state->update(['total_value' => '55.0001']);
        $this->assertTrue(app(VariantInventoryValuation::class)->read($this->actor, $variant, $this->branch->id)['fifo_mismatch']);
    }

    public function test_fifo_layer_row_uses_exact_value_at_a_display_rounding_boundary(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => Category::factory()])->id]);
        InventoryValuationSetting::create(['category_id' => $variant->product->category_id, 'costing_method' => 'fifo']);
        $layer = $this->layer($variant, '149.9849');
        $this->assertSame('49.9950', (string) app(FifoLayerValue::class)->remaining($layer));
        Livewire::test(VariantInventoryValuationPanel::class, ['variantId' => $variant->id, 'productId' => $variant->product_id])
            ->assertSee('EGP 50.00')->assertDontSee('EGP 49.99');
    }

    public function test_empty_customer_and_fully_consumed_fifo_have_zero_balances(): void
    {
        $customer = $this->customer();
        $financials = app(CustomerFinancials::class);
        $this->assertSame(['outstanding' => '0.0000', 'advance' => '0.0000', 'net_balance' => '0.0000'], $financials->customer($this->actor, $customer));
        $this->assertSame(0, $financials->history($this->actor, null, null, null, '', '')->count());
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => Category::factory()])->id]);
        InventoryValuationSetting::create(['category_id' => $variant->product->category_id, 'costing_method' => 'fifo']);
        $this->layer($variant, '0');
        InventoryCostState::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'on_hand_qty' => '0', 'avg_cost' => '0', 'total_value' => '0']);
        $valuation = app(VariantInventoryValuation::class)->read($this->actor, $variant, $this->branch->id);
        $this->assertSame('0.0000', $valuation['fifo_value']);
        $this->assertSame('0.0000', $valuation['fifo_quantity']);
        $this->assertSame(0, $valuation['layers']->total());
        $this->assertFalse($valuation['fifo_mismatch']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidResiduals(): array
    {
        return ['wrong residual' => [['remaining_value' => '49.6666']], 'missing residual' => [['remaining_value' => null]],
            'missing availability' => [['available_at' => null]]];
    }

    #[DataProvider('invalidResiduals')]
    public function test_fifo_invalid_exact_evidence_is_rejected(array $evidence): void
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['category_id' => Category::factory()])->id]);
        InventoryValuationSetting::create(['category_id' => $variant->product->category_id, 'costing_method' => 'fifo']);
        $layer = $this->layer($variant, '149');
        $this->assertSame('49.6667', (string) app(FifoLayerValue::class)->remaining($layer));
        if (in_array(null, $evidence, true)) {
            $this->expectException(QueryException::class);
            DB::table('fifo_layers')->where('id', $layer->id)->update($evidence);

            return;
        }
        DB::table('fifo_layers')->where('id', $layer->id)->update($evidence);
        $this->expectException(ValidationException::class);
        app(VariantInventoryValuation::class)->read($this->actor, $variant, $this->branch->id);
    }
}
