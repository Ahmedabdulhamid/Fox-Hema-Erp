<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Filament\Pages\CustomerPaymentHistory;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments;
use App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment;
use App\Modules\Payments\Filament\Resources\Customers\Pages\ListCustomers;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ViewSalesInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class CustomerSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private Customer $customer;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['payments', 'sales', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Customer payments branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...TreasuryPermissions::all(), ...SalesPermissions::all()]);
        $this->customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Customer A']);
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
    }

    private function data(string $amount = '100'): array
    {
        return ['customer_id' => $this->customer->id, 'payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id, 'amount' => $amount, 'payment_date' => now()->toDateString()];
    }

    private function payment(string $amount = '100'): Payment
    {
        $service = app(CustomerPayments::class);

        return $service->post($this->actor, $service->createDraft($this->actor, $this->data($amount), (string) Str::uuid7()));
    }

    private function invoice(string $total = '100', array $terms = []): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id, 'subtotal' => $total, 'total_amount' => $total]);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        $invoice = $invoice->fresh();
        app(CustomerAccounts::class)->linkInvoice($this->actor, $this->customer, $invoice, $terms, (string) Str::uuid7());

        return $invoice;
    }

    public function test_posting_is_atomic_idempotent_and_observers_generate_immutable_codes(): void
    {
        $key = (string) Str::uuid7();
        $service = app(CustomerPayments::class);
        $draft = $service->createDraft($this->actor, $this->data('100.0001'), $key);
        $this->assertSame($draft->id, $service->createDraft($this->actor, $this->data('100.0001'), $key)->id);
        $this->assertMatchesRegularExpression('/^CUS-\d{6}$/', $this->customer->code);
        $this->assertMatchesRegularExpression('/^RCPT-\d{6}$/', $draft->payment_number);
        $service->post($this->actor, $draft);
        $service->post($this->actor, $draft);
        $this->assertSame('100.0001', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_entries', 1);
        $draft->refresh()->payment_number = 'MANUAL';
        $this->expectException(ValidationException::class);
        $draft->saveQuietly();
    }

    public function test_partial_and_full_settlement_preserve_invoice_values_and_leave_an_advance(): void
    {
        $invoice = $this->invoice('100', ['settlement_terms' => 'mixed', 'planned_immediate_amount' => '40', 'due_date' => now()->toDateString()]);
        $payment = $this->payment('150');
        $service = app(CustomerAllocations::class);
        $key = (string) Str::uuid7();
        $first = $service->allocate($this->actor, $payment, $invoice, '40', $key);
        $this->assertSame($first->id, $service->allocate($this->actor, $payment, $invoice, '40', $key)->id);
        $this->assertSame('partial', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['payment_status']);
        $service->allocate($this->actor, $payment, $invoice, '60', (string) Str::uuid7());
        $balances = app(CustomerFinancials::class)->invoice($this->actor, $invoice);
        $this->assertSame('100.0000', $balances['paid']);
        $this->assertSame('0.0000', $balances['outstanding']);
        $this->assertSame('50.0000', app(CustomerFinancials::class)->available($payment));
        $this->assertSame('100.0000', $invoice->fresh()->total_amount);
        $this->assertDatabaseCount('treasury_entries', 1);
        $this->expectException(ValidationException::class);
        $service->allocate($this->actor, $payment, $invoice, '0.0001', (string) Str::uuid7());
    }

    public function test_refund_is_posted_exactly_once_and_competing_drafts_cannot_overrefund(): void
    {
        $payment = $this->payment();
        $service = app(CustomerRefunds::class);
        $data = ['payment_id' => $payment->id, 'amount' => '70', 'refund_date' => now()->toDateString(), 'reason' => 'Return unused advance'];
        $first = $service->createDraft($this->actor, $data, (string) Str::uuid7());
        $second = $service->createDraft($this->actor, $data, (string) Str::uuid7());
        $service->post($this->actor, $first);
        $service->post($this->actor, $first);
        $this->assertSame('30.0000', app(CustomerFinancials::class)->available($payment));
        $this->assertSame('30.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->expectException(ValidationException::class);
        $service->post($this->actor, $second);
    }

    public function test_credit_note_release_reallocation_and_refund_do_not_double_count_credit(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment();
        $allocations = app(CustomerAllocations::class);
        $allocation = $allocations->allocate($this->actor, $payment, $invoice, '100', (string) Str::uuid7());
        $creditId = DB::table('sales_credit_notes')->insertGetId(['uuid' => (string) Str::uuid7(), 'credit_note_number' => 'TEST-CN-1', 'sales_invoice_id' => $invoice->id,
            'location_id' => $this->branch->id, 'credit_date' => now()->toDateString(), 'status' => 'posted', 'subtotal' => '30', 'total_amount' => '30', 'created_by' => $this->actor->id, 'posted_by' => $this->actor->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('30.0000', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['excess_allocation']);
        $key = (string) Str::uuid7();
        $allocations->release($this->actor, $allocation, $key, 'Credit note adjustment');
        $allocations->release($this->actor, $allocation, $key, 'Credit note adjustment');
        $allocations->allocate($this->actor, $payment, $invoice, '70', (string) Str::uuid7());
        $service = app(CustomerRefunds::class);
        $service->post($this->actor, $service->createDraft($this->actor, ['payment_id' => $payment->id, 'sales_credit_note_id' => $creditId, 'amount' => '30', 'refund_date' => now()->toDateString(), 'reason' => 'Credit note refund'], (string) Str::uuid7()));
        $this->assertSame('0.0000', app(CustomerFinancials::class)->available($payment));
        $this->assertSame('0.0000', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['outstanding']);
        $this->assertSame('70.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $rows = app(CustomerFinancials::class)->history($this->actor, $this->customer->id, null, null, '', '')->get();
        $this->assertSame('0.0000', (string) $rows->last()->running_balance);
    }

    public function test_treasury_permission_failure_rolls_back_payment_posting(): void
    {
        $draft = app(CustomerPayments::class)->createDraft($this->actor, $this->data(), (string) Str::uuid7());
        $this->actor->revokePermissionTo(TreasuryPermissions::POST);
        try {
            app(CustomerPayments::class)->post($this->actor, $draft);
            $this->fail('Posting must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('treasury_entries', 0);
    }

    public function test_branch_scope_cannot_be_bypassed_with_view_all_permission(): void
    {
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $otherActor = User::factory()->create(['is_active' => true]);
        $otherActor->locations()->attach($other);
        setPermissionsTeamId($other->id);
        $otherActor->givePermissionTo(PaymentsPermissions::all());
        $draft = new Payment;
        try {
            app(CustomerPayments::class)->createDraft($otherActor, $this->data(), (string) Str::uuid7());
            $this->fail('Cross-branch mutation must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_foundation_post_and_independent_treasury_reversal_cannot_bypass_customer_workflow(): void
    {
        $draft = app(CustomerPayments::class)->createDraft($this->actor, $this->data(), (string) Str::uuid7());
        try {
            app(PaymentService::class)->post($this->actor, $draft);
            $this->fail('Foundation bypass');
        } catch (ValidationException) {
        }
        $payment = app(CustomerPayments::class)->post($this->actor, $draft);
        $movement = TreasuryOperation::where('payment_id', $payment->id)->firstOrFail();
        $this->expectException(ValidationException::class);
        app(TreasuryService::class)->reverse($this->actor, $movement, (string) Str::uuid7(), 'Bypass');
    }

    public function test_customer_receipt_history_is_read_only_and_backend_settlement_remains_available(): void
    {
        $invoice = $this->invoice();
        $payment = $this->payment();
        foreach ([ListCustomers::class, ListCustomerPayments::class, CustomerPaymentHistory::class] as $page) {
            Livewire::test($page)->assertSuccessful();
        }
        Livewire::test(ListCustomerPayments::class)->assertActionDoesNotExist('create');
        Livewire::test(ViewCustomerPayment::class, ['record' => $payment->uuid])->assertSuccessful()
            ->assertActionDoesNotExist('post')->assertActionDoesNotExist('allocate')
            ->assertActionDoesNotExist('refund')->assertActionDoesNotExist('allocations');
        app(CustomerAllocations::class)->allocate($this->actor, $payment, $invoice, '40', (string) Str::uuid7());
        $this->assertSame('60.0000', app(CustomerFinancials::class)->available($payment->fresh()));
        Livewire::withQueryParams(['customer' => $this->customer->id])->test(CustomerPaymentHistory::class)->assertSuccessful()->assertSee($payment->payment_number);
    }

    public function test_verified_refund_recovery_preserves_original_records_and_restores_funds_once(): void
    {
        $payment = $this->payment();
        $service = app(CustomerRefunds::class);
        $refund = $service->post($this->actor, $service->createDraft($this->actor, ['payment_id' => $payment->id, 'amount' => '40',
            'refund_date' => now()->toDateString(), 'reason' => 'Unused advance'], (string) Str::uuid7()));
        $key = (string) Str::uuid7();
        $recovered = $service->recover($this->actor, $refund, $key, 'Funds verified returned', 'RETURN-40');
        $service->recover($this->actor, $refund, $key, 'Funds verified returned', 'RETURN-40');
        $this->assertSame('posted', $recovered->status);
        $this->assertSame('40.0000', $recovered->amount);
        $this->assertNotNull($recovered->recovery_operation_id);
        $this->assertSame('100.0000', app(CustomerFinancials::class)->available($payment));
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('treasury_entries', 3);
        $this->expectException(ValidationException::class);
        $service->recover($this->actor, $refund, (string) Str::uuid7(), 'Duplicate recovery', 'RETURN-40');
    }

    public function test_refund_insufficient_funds_rolls_back_without_posting_or_duplicate_movements(): void
    {
        $payment = $this->payment();
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, ['type' => 'cash_withdrawal', 'account_id' => $this->cash->id, 'amount' => '80',
            'effective_date' => now()->toDateString(), 'reason' => 'Approved external expense', 'source_type' => 'external_expense', 'source_reference' => 'EXPENSE-80'], (string) Str::uuid7()));
        $service = app(CustomerRefunds::class);
        $refund = $service->createDraft($this->actor, ['payment_id' => $payment->id, 'amount' => '50', 'refund_date' => now()->toDateString(), 'reason' => 'Advance return'], (string) Str::uuid7());
        try {
            $service->post($this->actor, $refund);
            $this->fail('Insufficient balance must fail.');
        } catch (ValidationException) {
        }
        $this->assertSame('draft', $refund->fresh()->status);
        $this->assertNull($refund->fresh()->treasury_operation_id);
        $this->assertSame('20.0000', $treasury->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->assertSame('100.0000', app(CustomerFinancials::class)->available($payment));
    }

    public function test_conflicting_retries_and_other_customer_invoice_allocations_are_rejected(): void
    {
        $key = (string) Str::uuid7();
        $service = app(CustomerPayments::class);
        $draft = $service->createDraft($this->actor, $this->data('50'), $key);
        try {
            $service->createDraft($this->actor, $this->data('60'), $key);
            $this->fail('Conflicting retry.');
        } catch (ValidationException) {
        }
        $payment = $service->post($this->actor, $draft);
        $original = $this->customer;
        $this->customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Customer B']);
        $invoice = $this->invoice();
        $this->customer = $original;
        $this->expectException(ValidationException::class);
        app(CustomerAllocations::class)->allocate($this->actor, $payment, $invoice, '50', (string) Str::uuid7());
    }

    public function test_account_method_mismatch_is_rejected_and_customer_factory_uses_observers(): void
    {
        $customer = Customer::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->assertMatchesRegularExpression('/^CUS-\d{6}$/', $customer->code);
        $bank = PaymentMethod::factory()->create(['type' => 'bank_transfer', 'requires_reference' => true]);
        $this->expectException(ValidationException::class);
        app(CustomerPayments::class)->createDraft($this->actor, array_replace($this->data(), ['payment_method_id' => $bank->id, 'external_reference' => 'BANK-1']), (string) Str::uuid7());
    }

    public function test_sales_settlement_actions_and_payments_navigation_work_with_ready_schema(): void
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id,
            'subtotal' => '100', 'total_amount' => '100']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        $page = Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid]);
        $page->assertActionVisible('linkCustomerInvoice')->assertActionVisible('receivePayment')
            ->callAction('linkCustomerInvoice', ['operation_key' => (string) Str::uuid7(), 'customer_id' => $this->customer->id,
                'settlement_terms' => 'cash', 'planned_immediate_amount' => '100', 'due_date' => null])->assertHasNoActionErrors();
        $this->assertDatabaseHas('customer_invoice_accounts', ['sales_invoice_id' => $invoice->id, 'customer_id' => $this->customer->id, 'settlement_terms' => 'cash']);
        $resource = CustomerPaymentResource::class;
        $this->assertSame([], $resource::getNavigationItems());
        $this->assertFalse($resource::shouldRegisterNavigation());
    }
}
