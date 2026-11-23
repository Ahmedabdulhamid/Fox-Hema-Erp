<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Filament\Pages\CustomerPaymentHistory;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment;
use App\Modules\Payments\Filament\Resources\CustomerRefunds\CustomerRefundResource;
use App\Modules\Payments\Filament\Resources\Customers\CustomerResource;
use App\Modules\Payments\Filament\Resources\PaymentAllocations\PaymentAllocationResource;
use App\Modules\Payments\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class RetailSalesPaymentsTest extends TestCase
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

    private function invoice(): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id,
            'customer_name' => null, 'customer_phone' => null, 'subtotal' => '100', 'total_amount' => '100']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);

        return $invoice->fresh();
    }

    private function data(string $amount = '100'): array
    {
        return ['payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id,
            'amount' => $amount, 'payment_date' => now()->toDateString()];
    }

    public function test_walk_in_partial_and_full_payments_reuse_treasury_once_without_creating_customers(): void
    {
        $invoice = $this->invoice();
        $service = app(CustomerPayments::class);
        $key = (string) Str::uuid7();
        $payment = $service->payInvoice($this->actor, $invoice, $this->data('40'), $key);
        $this->assertSame($payment->id, $service->payInvoice($this->actor, $invoice, $this->data('40'), $key)->id);
        $this->assertNull($payment->customer_id);
        $this->assertSame('sales_invoice', $payment->source_document_type);
        $this->assertMatchesRegularExpression('/^RCPT-\d{6}$/', $payment->payment_number);
        $this->assertSame('partial', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['payment_status']);
        $service->payInvoice($this->actor, $invoice, $this->data('60'), (string) Str::uuid7());
        $this->assertSame('settled', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['payment_status']);
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->assertDatabaseCount('payment_customers', 1);
        $this->assertDatabaseCount('customer_invoice_accounts', 0);
    }

    public function test_card_and_bank_transfer_receipts_reach_only_the_branch_bank_account(): void
    {
        $bank = TreasuryAccount::factory()->create(['type' => 'bank', 'location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        foreach (['card', 'bank_transfer'] as $type) {
            $method = PaymentMethod::factory()->create(['type' => $type, 'requires_reference' => true]);
            $data = array_replace($this->data(), ['payment_method_id' => $method->id, 'treasury_account_id' => $bank->id, 'external_reference' => 'REF-'.$type]);
            $payment = app(CustomerPayments::class)->payInvoice($this->actor, $this->invoice(), $data, (string) Str::uuid7());
            $this->assertSame($bank->id, $payment->treasury_account_id);
        }
        $this->assertSame('200.0000', app(TreasuryService::class)->balance($this->actor, $bank));
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_entries', 2);
    }

    public function test_overpayment_and_conflicting_retry_roll_back_all_financial_records(): void
    {
        $invoice = $this->invoice();
        $key = (string) Str::uuid7();
        $service = app(CustomerPayments::class);
        $service->payInvoice($this->actor, $invoice, $this->data('90'), $key);
        foreach ([[$this->data('11'), (string) Str::uuid7()], [$this->data('91'), $key]] as [$data, $requestKey]) {
            try {
                $service->payInvoice($this->actor, $invoice, $data, $requestKey);
                $this->fail('Invalid payment accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('payments', 1);
                $this->assertDatabaseCount('treasury_entries', 1);
                $this->assertDatabaseCount('payment_allocations', 1);
            }
        }
        $this->assertSame('90.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    public function test_receipts_cannot_bypass_the_atomic_workflow_or_be_independently_reversed(): void
    {
        $payment = app(CustomerPayments::class)->payInvoice($this->actor, $this->invoice(), $this->data(), (string) Str::uuid7());
        foreach ([
            fn () => app(PaymentService::class)->post($this->actor, $payment),
            fn () => app(PaymentService::class)->reverse($this->actor, $payment, (string) Str::uuid7(), 'Invalid independent reversal'),
            fn () => app(TreasuryService::class)->reverse($this->actor, TreasuryOperation::where('payment_id', $payment->id)->firstOrFail(), (string) Str::uuid7(), 'Invalid independent reversal'),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Independent mutation accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('treasury_entries', 1);
            }
        }
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    public function test_payments_reject_inactive_methods_wrong_accounts_drafts_and_foreign_branches(): void
    {
        $invoice = $this->invoice();
        $inactive = PaymentMethod::factory()->create(['type' => 'cash', 'is_active' => false]);
        $card = PaymentMethod::factory()->create(['type' => 'card', 'requires_reference' => false]);
        foreach ([$inactive, $card] as $method) {
            try {
                app(CustomerPayments::class)->payInvoice($this->actor, $invoice, array_replace($this->data(), ['payment_method_id' => $method->id]), (string) Str::uuid7());
                $this->fail('Ineligible method/account accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('payments', 0);
            }
        }
        $other = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $foreign = SalesInvoice::factory()->create(['location_id' => $other->id, 'created_by' => $this->actor->id]);
        try {
            app(CustomerPayments::class)->payInvoice($this->actor, $foreign, $this->data(), (string) Str::uuid7());
            $this->fail('Foreign branch accepted.');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('treasury_entries', 0);
        }
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'draft']);
        $this->expectException(ValidationException::class);
        app(CustomerPayments::class)->payInvoice($this->actor, $invoice, $this->data(), (string) Str::uuid7());
    }

    public function test_linked_customer_invoice_reuses_existing_settlement_and_permission_failures_are_atomic(): void
    {
        $invoice = $this->invoice();
        app(CustomerAccounts::class)->linkInvoice($this->actor, $this->customer, $invoice, [], (string) Str::uuid7());
        $payment = app(CustomerPayments::class)->payInvoice($this->actor, $invoice, $this->data('40'), (string) Str::uuid7());
        $this->assertSame($this->customer->id, $payment->customer_id);
        $this->actor->revokePermissionTo(TreasuryPermissions::POST);
        try {
            app(CustomerPayments::class)->payInvoice($this->actor, $invoice, $this->data('60'), (string) Str::uuid7());
            $this->fail('Posting permission bypassed.');
        } catch (AuthorizationException|HttpException) {
            $this->assertDatabaseCount('payments', 1);
            $this->assertDatabaseCount('treasury_entries', 1);
            $this->assertDatabaseCount('payment_allocations', 1);
        }
    }

    public function test_sales_invoice_payment_action_works_and_retail_navigation_stays_simple(): void
    {
        $invoice = $this->invoice();
        Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid])
            ->assertActionVisible('receivePayment')->assertActionDoesNotExist('customerPayments')
            ->callAction('receivePayment', $this->data('40') + ['operation_key' => (string) Str::uuid7()])->assertHasNoActionErrors()
            ->assertSee('RCPT-000001');
        $this->assertDatabaseHas('payments', ['customer_id' => null, 'amount' => '40.0000', 'status' => 'posted']);
        foreach ([CustomerPaymentResource::class,
            CustomerRefundResource::class,
            PaymentAllocationResource::class,
            CustomerPaymentHistory::class] as $page) {
            $this->assertFalse($page::shouldRegisterNavigation());
        }
        $this->assertSame([], CustomerPaymentResource::getNavigationItems());
        $this->assertSame(__('Sales'), CustomerResource::getNavigationGroup());
        $this->assertSame(__('payments.settings'), PaymentMethodResource::getNavigationGroup());
        $this->assertSame('40.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        Livewire::test(ViewCustomerPayment::class, ['record' => Payment::firstOrFail()->uuid])->assertSuccessful();
    }
}
