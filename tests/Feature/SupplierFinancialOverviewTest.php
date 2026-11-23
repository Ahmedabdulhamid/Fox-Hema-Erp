<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\SupplierFinance;
use App\Modules\Purchasing\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\ViewVendorPayment;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\PurchaseSettlement;
use App\Modules\Purchasing\Services\PurchaseSettlementTerms;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
use App\Modules\Purchasing\Services\SupplierFinancialOverview;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SupplierFinancialOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private Supplier $supplier;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 20)->setTime(10, 0));
        app()->setLocale('en');
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['purchasing', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Finance branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PurchasingPermissions::all(), ...TreasuryPermissions::all()]);
        $this->supplier = Supplier::factory()->create();
        $this->method = PaymentMethod::factory()->create();
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, ['type' => 'opening_balance', 'account_id' => $this->cash->id,
            'amount' => '1000000', 'effective_date' => now()->toDateString(), 'reason' => 'Isolated Phase 5 funds'], (string) Str::uuid7()));
    }

    private function bill(string $total = '10000', ?Supplier $supplier = null, ?Location $location = null, string $status = 'posted'): VendorBill
    {
        $order = PurchaseOrder::factory()->create(['supplier_id' => ($supplier ?? $this->supplier)->id,
            'location_id' => ($location ?? $this->branch)->id, 'created_by' => $this->actor->id, 'status' => 'confirmed']);
        $bill = VendorBill::factory()->create(['supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id,
            'total_amount' => $total, 'subtotal' => $total, 'due_date' => now()->subDay()->toDateString(), 'created_by' => $this->actor->id]);
        DB::table('vendor_bills')->where('id', $bill->id)->update(['status' => $status, 'posted_at' => $status === 'posted' ? now() : null, 'posted_by' => $status === 'posted' ? $this->actor->id : null]);

        return $bill->fresh();
    }

    private function paymentData(string $amount = '4000'): array
    {
        return ['supplier_id' => $this->supplier->id, 'location_id' => $this->branch->id, 'treasury_account_id' => $this->cash->id,
            'payment_method_id' => $this->method->id, 'payment_date' => now()->toDateString(), 'amount' => $amount];
    }

    private function payment(string $amount = '4000'): VendorPayment
    {
        $service = app(VendorPaymentService::class);

        return $service->post($this->actor, $service->createDraft($this->actor, $this->paymentData($amount), (string) Str::uuid7()));
    }

    private function report(?string $cutoff = null, ?string $from = null, int $page = 1): array
    {
        return app(SupplierFinancialOverview::class)->report($this->actor, $this->supplier->id, cutoff: $cutoff, from: $from, page: $page);
    }

    private function allocate(VendorPayment $payment, VendorBill $bill, string $amount): mixed
    {
        return app(VendorPaymentAllocationService::class)->allocate($this->actor, $payment, $bill, $amount, (string) Str::uuid7());
    }

    private function invalid(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid financial operation accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized operation accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_empty_supplier_and_unpaid_overdue_bill(): void
    {
        $report = $this->report();
        $this->assertSame('0.0000', $report['totals']['net_supplier_balance']);
        $this->assertSame(0, $report['statement']->total());
        $this->bill('10000.0001');
        $report = $this->report();
        $this->assertSame('10000.0001', $report['totals']['billed']);
        $this->assertSame('10000.0001', $report['totals']['overdue']);
        $this->assertSame('10000.0001', $report['statement']->last()->running_balance);
    }

    public function test_advance_partial_and_full_settlement_reconcile_without_duplicate_cash(): void
    {
        $payment = $this->payment('12000.0001');
        $this->assertSame('-12000.0001', $this->report()['totals']['net_supplier_balance']);
        $bill = $this->bill('10000.0001');
        $entries = DB::table('treasury_entries')->count();
        $this->allocate($payment, $bill, '4000');
        $report = $this->report();
        $this->assertSame('6000.0001', $report['totals']['gross_open_invoices']);
        $this->assertSame('8000.0001', $report['totals']['unallocated_advances']);
        $this->assertSame('-2000.0000', $report['totals']['net_supplier_balance']);
        $this->allocate($payment, $bill, '6000.0001');
        $report = $this->report();
        $this->assertSame('0.0000', $report['totals']['gross_open_invoices']);
        $this->assertSame('2000.0000', $report['totals']['unallocated_advances']);
        $this->assertSame('-2000.0000', $report['statement']->last()->running_balance);
        $this->assertSame($entries, DB::table('treasury_entries')->count());
        $this->assertSame(app(SupplierFinancialBalances::class)->supplier($this->actor, $this->supplier->id)['net_supplier_balance'], $report['totals']['net_supplier_balance']);
    }

    public function test_multiple_payments_and_bills_then_release_and_recovery_keep_audit_activity(): void
    {
        $bill = $this->bill();
        $other = $this->bill('2000');
        $first = $this->payment('6000');
        $second = $this->payment('6000');
        $tranche = $this->allocate($first, $bill, '6000');
        app(VendorPaymentAllocationService::class)->allocateMany($this->actor, $second, [
            ['bill_id' => $bill->id, 'amount' => '4000', 'operation_key' => (string) Str::uuid7()],
            ['bill_id' => $other->id, 'amount' => '2000', 'operation_key' => (string) Str::uuid7()],
        ]);
        $this->assertSame('0.0000', $this->report()['totals']['gross_open_invoices']);
        $this->travel(1)->days();
        app(VendorPaymentAllocationService::class)->release($this->actor, $tranche, (string) Str::uuid7(), 'Wrong tranche');
        $report = $this->report();
        $this->assertSame('6000.0000', $report['totals']['gross_open_invoices']);
        $this->assertSame('6000.0000', $report['totals']['unallocated_advances']);
        $this->assertSame('0.0000', $report['totals']['net_supplier_balance']);
        $this->assertSame('release', $report['statement']->last()->entry_type);
        $this->travel(1)->days();
        app(VendorPaymentService::class)->recover($this->actor, $second, (string) Str::uuid7(), 'Verified refund', 'Receipt-5');
        $report = $this->report();
        $this->assertSame('12000.0000', $report['totals']['gross_open_invoices']);
        $this->assertSame('6000.0000', $report['totals']['payments']);
        $this->assertSame('6000.0000', $report['statement']->last()->running_balance);
        $this->assertSame('recovery', $report['statement']->last()->entry_type);
        $this->assertSame('0.0000', $this->report('2026-10-20')['totals']['gross_open_invoices']);
    }

    public function test_cutoff_opening_and_stable_paginated_running_balances(): void
    {
        for ($index = 0; $index < 28; $index++) {
            $this->bill('1.0001');
        }
        $first = $this->report();
        $second = $this->report(page: 2);
        $this->assertSame(28, $first['statement']->total());
        $this->assertSame('25.0025', $first['statement']->last()->running_balance);
        $this->assertSame('26.0026', $second['statement']->first()->running_balance);
        $this->assertSame('28.0028', $second['statement']->last()->running_balance);
        $this->assertSame($first['totals']['net_supplier_balance'], $second['statement']->last()->running_balance);
        $this->assertSame($second['statement']->pluck('event_id')->all(), $this->report(page: 2)['statement']->pluck('event_id')->all());
        $this->travel(1)->days();
        $this->payment('10');
        $range = $this->report(from: '2026-10-21');
        $this->assertSame('28.0028', $range['opening']);
        $this->assertSame(1, $range['statement']->total());
        $this->assertSame('18.0028', $range['statement']->last()->running_balance);
        $this->assertSame('28.0028', $this->report('2026-10-20')['totals']['net_supplier_balance']);
    }

    public function test_draft_cancelled_and_unposted_payments_are_excluded(): void
    {
        $this->bill(status: 'draft');
        $this->bill(status: 'cancelled');
        app(VendorPaymentService::class)->createDraft($this->actor, $this->paymentData(), (string) Str::uuid7());
        $report = $this->report();
        $this->assertSame('0.0000', $report['totals']['billed']);
        $this->assertSame('0.0000', $report['totals']['payments']);
        $this->assertSame(0, $report['statement']->total());
    }

    public function test_new_payment_and_allocation_are_atomic_and_retry_safe(): void
    {
        $bill = $this->bill();
        $key = (string) Str::uuid7();
        $allocations = [['bill_id' => $bill->id, 'amount' => '4000', 'operation_key' => (string) Str::uuid7()]];
        $service = app(PurchaseSettlement::class);
        $payment = $service->pay($this->actor, $this->paymentData(), $key, $allocations);
        $this->assertSame($payment->id, $service->pay($this->actor, $this->paymentData(), $key, $allocations)->id);
        $this->assertSame('6000.0000', $this->report()['totals']['gross_open_invoices']);
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->invalid(fn () => $service->pay($this->actor, $this->paymentData('7000'), (string) Str::uuid7(),
            [['bill_id' => $bill->id, 'amount' => '7000', 'operation_key' => (string) Str::uuid7()]]));
        $this->assertDatabaseCount('vendor_payments', 1);
        $this->assertDatabaseCount('treasury_entries', 2);
    }

    public function test_cross_supplier_and_location_settlement_roll_back_the_new_payment(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        foreach ([$this->bill(supplier: Supplier::factory()->create()), $this->bill(location: $other)] as $bill) {
            $this->invalid(fn () => app(PurchaseSettlement::class)->pay($this->actor, $this->paymentData(), (string) Str::uuid7(),
                [['bill_id' => $bill->id, 'amount' => '4000', 'operation_key' => (string) Str::uuid7()]]));
        }
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    public function test_branch_scope_and_all_location_read_permission_never_grant_cross_branch_writes(): void
    {
        $other = Location::create(['name' => 'Private branch', 'type' => 'branch', 'is_active' => true]);
        $this->bill('100');
        $otherBill = $this->bill('900', location: $other);
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertSame('100.0000', $this->report()['totals']['billed']);
        $this->denied(fn () => app(SupplierFinancialOverview::class)->report($this->actor, $this->supplier->id, $other->id));
        $this->actor->givePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertSame('1000.0000', $this->report()['totals']['billed']);
        $this->assertSame('900.0000', app(SupplierFinancialOverview::class)->report($this->actor, $this->supplier->id, $other->id)['totals']['billed']);
        $this->invalid(fn () => $this->allocate($this->payment('10'), $otherBill, '10'));
    }

    public function test_read_permission_revocation_and_module_deactivation_are_fresh(): void
    {
        $this->report();
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        $this->denied(fn () => $this->report());
        $this->actor->givePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        $this->report();
        Module::where('code', 'purchasing')->update(['is_active' => false]);
        $this->denied(fn () => $this->report());
    }

    public function test_legacy_unlinked_managed_payments_are_visible_but_unknown_ownership_fails_closed(): void
    {
        $payment = $this->payment();
        DB::table('vendor_payments')->where('id', $payment->id)->update(['treasury_account_id' => null]);
        $this->assertSame('4000.0000', $this->report()['totals']['unallocated_advances']);
        app(VendorPaymentService::class)->reverse($this->actor, $payment->fresh(), (string) Str::uuid7(), 'Legacy correction');
        $report = $this->report();
        $this->assertSame('0.0000', $report['totals']['net_supplier_balance']);
        $this->assertSame('reversal', $report['statement']->last()->entry_type);
        DB::table('vendor_payments')->where('id', $payment->id)->update(['location_id' => null]);
        $this->invalid(fn () => $this->report());
    }

    public static function terms(): array
    {
        return ['cash' => ['cash', '10000', '0.0000'], 'credit' => ['credit', '0', '10000.0000'], 'mixed' => ['mixed', '4000', '6000.0000']];
    }

    #[DataProvider('terms')]
    public function test_commercial_terms_do_not_mark_bills_paid_or_move_cash(string $terms, string $immediate, string $remaining): void
    {
        $data = app(PurchaseSettlementTerms::class)->validate(['settlement_terms' => $terms, 'planned_immediate_amount' => $immediate,
            'bill_date' => '2026-10-20', 'due_date' => '2026-11-20'], '10000.0000');
        $this->assertSame($terms, $data['settlement_terms']);
        $this->assertSame($remaining, (string) BigDecimal::of('10000')->minus($data['planned_immediate_amount'])->toScale(4));
        $bill = $this->bill();
        DB::table('vendor_bills')->where('id', $bill->id)->update($data);
        $this->assertSame('10000.0000', $this->report()['totals']['gross_open_invoices']);
        $this->assertSame('0.0000', $this->report()['totals']['allocated']);
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    public function test_invalid_terms_due_dates_and_precision_are_rejected_without_backfill(): void
    {
        $service = app(PurchaseSettlementTerms::class);
        $this->assertSame(['settlement_terms' => null, 'planned_immediate_amount' => null], $service->validate([], '10000'));
        foreach ([['cash', '4000'], ['credit', '1'], ['mixed', '0'], ['mixed', '10000'], ['mixed', '10000.0001'], ['mixed', 0.1], ['mixed', '1.00001']] as [$terms, $amount]) {
            $this->invalid(fn () => $service->validate(['settlement_terms' => $terms, 'planned_immediate_amount' => $amount], '10000'));
        }
        $this->invalid(fn () => $service->validate(['settlement_terms' => 'credit', 'planned_immediate_amount' => '0'], '10000'));
        $this->assertNull($this->bill()->settlement_terms);
    }

    public function test_supplier_finance_livewire_and_bill_actions_enforce_independent_mutation_permissions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $bill = $this->bill();
        $payment = $this->payment();
        Livewire::test(SupplierFinance::class, ['record' => $this->supplier->slug])->assertSee('Supplier statement')->assertSee('6000.0000');
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])
            ->callAction('allocateAdvance', ['payment_id' => $payment->id, 'amount' => '4000', 'operation_key' => (string) Str::uuid7()])->assertHasNoActionErrors();
        $this->assertSame('6000.0000', $this->report()['totals']['gross_open_invoices']);
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_ALLOCATE);
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->assertActionHidden('allocateAdvance')->assertActionHidden('settleBill');
        Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid])->assertActionHidden('allocateBills');
    }

    public function test_settle_bill_action_posts_and_allocates_once_and_displays_validation(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $bill = $this->bill();
        $keys = ['operation_key' => (string) Str::uuid7(), 'allocation_key' => (string) Str::uuid7()];
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->callAction('settleBill', $this->paymentData('10000') + $keys)->assertHasNoActionErrors();
        $this->assertSame('0.0000', $this->report()['totals']['gross_open_invoices']);
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->assertDatabaseCount('vendor_payment_allocations', 1);
        Livewire::test(ViewVendorBill::class, ['record' => $bill->uuid])->callAction('settleBill', $this->paymentData('1') + [
            'operation_key' => (string) Str::uuid7(), 'allocation_key' => (string) Str::uuid7(),
        ])->assertNotified(__('Cannot complete vendor payment'));
        $this->assertDatabaseCount('treasury_entries', 2);
    }

    public function test_payment_page_allocates_multiple_bills_and_releases_without_treasury_entries(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $first = $this->bill('2000');
        $second = $this->bill('2000');
        $payment = $this->payment('4000');
        Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid])->callAction('allocateBills', ['allocations' => [
            ['bill_id' => $first->id, 'amount' => '2000', 'operation_key' => (string) Str::uuid7()],
            ['bill_id' => $second->id, 'amount' => '2000', 'operation_key' => (string) Str::uuid7()],
        ]])->assertHasNoActionErrors();
        $this->assertSame('0.0000', $this->report()['totals']['gross_open_invoices']);
        Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid])->callAction('releaseAllocation', [
            'allocation_id' => $payment->allocations()->firstOrFail()->id, 'operation_key' => (string) Str::uuid7(), 'reason' => 'Correct bill association',
        ])->assertHasNoActionErrors();
        $this->assertSame('2000.0000', $this->report()['totals']['unallocated_advances']);
        $this->assertDatabaseCount('treasury_entries', 2);
    }

    public function test_payment_allocation_selector_excludes_settled_bills_and_revalidates_the_remaining_amount(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $settledBill = $this->bill('1000');
        $openBill = $this->bill('3000');
        DB::table('vendor_bills')->where('id', $settledBill->id)->update(['bill_number' => 'FH-SETTLED-BILL']);
        DB::table('vendor_bills')->where('id', $openBill->id)->update(['bill_number' => 'FH-OPEN-BILL']);
        $payment = $this->payment('3000');
        $this->allocate($payment, $settledBill, '1000');
        $balancesBefore = app(SupplierFinancialBalances::class)->payment($this->actor, $payment);

        $url = VendorPaymentResource::getUrl('view', ['record' => $payment->uuid]);
        $endpoint = null;
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->getName() ?? '', 'livewire.update')) {
                $endpoint = '/'.$route->uri();
                break;
            }
        }
        $this->assertNotNull($endpoint);
        app(LocationContext::class)->setFor($this->actor, $this->branch->id);
        $pageResponse = $this->get($url)->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $pageResponse->getContent(), $matches);
        $snapshot = null;
        foreach ($matches[1] as $candidate) {
            $decoded = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            if ((json_decode($decoded, true)['memo']['name'] ?? '') === ViewVendorPayment::class) {
                $snapshot = $decoded;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [],
            'calls' => [['path' => '', 'method' => 'mountAction', 'params' => ['allocateBills', [], []]]]]]];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $modalResponse = $this->withHeader('X-Livewire', 'true')->postJson($endpoint, $payload)->assertOk();
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();
        $allocationModalQueries = count($queryLog);

        $this->assertStringContainsString('FH-OPEN-BILL', $modalResponse->getContent());
        $this->assertStringNotContainsString('FH-SETTLED-BILL', $modalResponse->getContent());
        $this->assertLessThanOrEqual(25, $allocationModalQueries);
        app(LocationContext::class)->setFor($this->actor, $this->branch->id);
        $this->assertSame($balancesBefore, app(SupplierFinancialBalances::class)->payment($this->actor, $payment));
        Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid])->callAction('allocateBills', ['allocations' => [
            ['bill_id' => $openBill->id, 'amount' => '2000', 'operation_key' => (string) Str::uuid7()],
        ]])->assertHasNoActionErrors();
        $this->assertSame('0.0000', app(SupplierFinancialBalances::class)->payment($this->actor, $payment)['unallocated']);
        $this->assertSame('1000.0000', app(SupplierFinancialBalances::class)->bill($this->actor, $openBill)['outstanding']);
        $page = Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid]);
        $page->assertActionDisabled('allocateBills');
        $this->assertSame(__('This payment is fully allocated.'), $page->instance()->getAction('allocateBills')->getTooltip());
        $this->invalid(fn () => $this->allocate($payment, $openBill, '0.0001'));
    }

    public function test_release_selector_preloads_only_active_same_branch_allocations_without_changing_balances(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        $releasedBill = $this->bill('1000');
        $activeBill = $this->bill('1000');
        DB::table('vendor_bills')->where('id', $releasedBill->id)->update(['bill_number' => 'FH-RELEASED-BILL']);
        DB::table('vendor_bills')->where('id', $activeBill->id)->update(['bill_number' => 'FH-ACTIVE-BILL']);
        $payment = $this->payment('3000');
        $releasedAllocation = $this->allocate($payment, $releasedBill, '1000');
        $activeAllocation = $this->allocate($payment, $activeBill, '1000');
        app(VendorPaymentAllocationService::class)->release($this->actor, $releasedAllocation, (string) Str::uuid7(), 'Release test allocation');
        $balancesBefore = app(SupplierFinancialBalances::class)->payment($this->actor, $payment);

        $page = Livewire::test(ViewVendorPayment::class, ['record' => $payment->uuid]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->mountAction('releaseAllocation')
            ->assertMountedActionModalSee(['FH-ACTIVE-BILL', '1000.0000 EGP'])
            ->assertMountedActionModalDontSee('FH-RELEASED-BILL');
        $releaseModalQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $releaseModalQueries);
        $this->assertSame($balancesBefore, app(SupplierFinancialBalances::class)->payment($this->actor, $payment));

        $foreign = Location::create(['name' => 'Foreign settlement branch', 'type' => 'branch', 'is_active' => true]);
        $foreignBill = $this->bill('1000', location: $foreign);
        DB::table('vendor_bills')->where('id', $foreignBill->id)->update(['bill_number' => 'FH-FOREIGN-BILL']);
        $page->unmountAction()->mountAction('allocateBills')
            ->assertMountedActionModalSee('FH-RELEASED-BILL')
            ->assertMountedActionModalDontSee(['FH-ACTIVE-BILL', 'FH-FOREIGN-BILL']);

        DB::table('purchase_orders')->where('id', $activeBill->purchase_order_id)->update(['location_id' => $foreign->id]);
        $page->unmountAction()->mountAction('releaseAllocation')
            ->assertMountedActionModalDontSee('FH-ACTIVE-BILL');
        $this->assertSame($activeAllocation->id, $activeBill->paymentAllocations()->whereNull('released_at')->value('id'));
    }

    public function test_global_admin_visibility_branch_switch_and_actor_isolation(): void
    {
        $other = Location::create(['name' => 'Second branch', 'type' => 'branch', 'is_active' => true]);
        $this->bill('100');
        $this->bill('900', location: $other);
        $admin = $this->provisionTestSuperAdmin();
        $this->assertSame('1000.0000', app(SupplierFinancialOverview::class)->report($admin, $this->supplier->id)['totals']['billed']);
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW_ALL_LOCATIONS);
        $this->assertSame('100.0000', $this->report()['totals']['billed']);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->locations()->attach($other);
        setPermissionsTeamId($other->id);
        $viewer->givePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        $this->assertSame('900.0000', app(SupplierFinancialOverview::class)->report($viewer, $this->supplier->id)['totals']['billed']);
        $this->denied(fn () => $this->report());
        setPermissionsTeamId($this->branch->id);
        $this->assertSame('100.0000', $this->report()['totals']['billed']);
    }

    public function test_corrupt_ownership_and_missing_recognition_evidence_fail_closed(): void
    {
        $bill = $this->bill();
        $payment = $this->payment();
        $allocation = $this->allocate($payment, $bill, '4000');
        $other = Location::create(['name' => 'Mismatched ownership', 'type' => 'branch', 'is_active' => true]);
        DB::table('purchase_orders')->where('id', $bill->purchase_order_id)->update(['location_id' => $other->id]);
        $this->invalid(fn () => $this->report());
        DB::table('purchase_orders')->where('id', $bill->purchase_order_id)->update(['location_id' => $this->branch->id]);
        DB::table('vendor_bills')->where('id', $bill->id)->update(['posted_at' => null]);
        $this->invalid(fn () => $this->report());
    }

    public function test_read_only_finance_requests_and_row_growth_have_bounded_queries(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
        config(['debugbar.enabled' => false]);
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        $this->bill('10000.0001');
        $this->payment('4000');
        $measurements = [];
        $measure = function (string $name, \Closure $operation) use (&$measurements): mixed {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $result = $operation();
            $elapsed = (hrtime(true) - $start) / 1000000;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $fingerprints = array_map(fn (array $query): string => $query['query'].serialize($query['bindings']), $queries);
            $measurements[$name] = ['queries' => count($queries), 'duplicates' => count($queries) - count(array_unique($fingerprints)),
                'sql_ms' => array_sum(array_column($queries, 'time')), 'request_ms' => $elapsed];

            return $result;
        };
        $measure('service_one_bill', fn () => $this->report());
        for ($index = 0; $index < 30; $index++) {
            $this->bill('1');
        }
        $measure('service_31_bills', fn () => $this->report(page: 2));
        $this->assertSame($measurements['service_one_bill']['queries'], $measurements['service_31_bills']['queries']);
        app(LocationContext::class)->setFor($this->actor, $this->branch->id);
        $url = SupplierResource::getUrl('finance', ['record' => $this->supplier]);
        $response = $measure('supplier_finance_get', fn () => $this->get($url)->assertOk()->assertSee('Supplier statement'));
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = null;
        foreach ($matches[1] as $candidate) {
            $decoded = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            if ((json_decode($decoded, true)['memo']['name'] ?? '') === SupplierFinance::class) {
                $snapshot = $decoded;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        $endpoint = null;
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->getName() ?? '', 'livewire.update')) {
                $endpoint = '/'.$route->uri();
                break;
            }
        }
        $this->assertNotNull($endpoint);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['from' => '2026-10-20'],
            'calls' => [['path' => '', 'method' => 'gotoPage', 'params' => [2, 'statementPage']]]]]];
        $measure('statement_page_two', fn () => $this->withHeader('X-Livewire', 'true')->postJson($endpoint, $payload)->assertOk());
        file_put_contents(storage_path('framework/testing/phase5-benchmarks.json'), json_encode($measurements, JSON_PRETTY_PRINT));
        setPermissionsTeamId($this->branch->id);
        $this->actor->revokePermissionTo(PurchasingPermissions::PAYMENTS_VIEW);
        Livewire::flushState();
        $this->postJson($endpoint, $payload)->assertForbidden();
        $this->get($url)->assertForbidden();
    }
}
