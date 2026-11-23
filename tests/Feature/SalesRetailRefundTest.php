<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\ViewSalesCreditNote;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ViewSalesInvoice;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoicePdfData;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SalesRetailRefundTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesInvoiceService $service;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/^(?:8\.|[0-9]+\.[0-9]+\.[0-9]+-MariaDB)/', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing', 'payments', 'treasury'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->branch = Location::create(['name' => 'Invoice branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'قميص Fox & Hema', 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->configureSalesPricing($this->variant);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->service = app(SalesInvoiceService::class);
    }

    private function order(int $lineCount = 1, string $quantity = '3'): SalesOrder
    {
        if (! StockBalance::where('variant_id', $this->variant->id)->exists()) {
            $orders = app(PurchaseOrderService::class);
            $receipts = app(PurchaseReceiptService::class);
            $purchase = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
                'location_id' => $this->branch->id, 'order_date' => '2026-10-17',
                'items' => [['product_id' => $this->variant->product_id,
                    'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                    'variant_id' => $this->variant->id, 'quantity' => '300', 'unit_price' => '2']]], (string) Str::uuid7());
            $purchase = $orders->confirm($this->actor, $purchase, $orders->revision($purchase));
            $receipt = $purchase->receipts()->firstOrFail();
            $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
                'quantity' => '300', 'unit_cost' => '2']]], $receipts->revision($receipt));
            $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        }
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'customer_name' => 'عميل تجريبي', 'customer_phone' => null,
            'items' => array_map(fn (int $index): array => ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'description' => 'Premium cotton shirt / قميص قطني فاخر', 'quantity' => $quantity,
                'unit_price' => $index === 0 ? '10.123456' : '7.654321'], range(0, $lineCount - 1))], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    private function deliver(SalesOrder $order, ?string $quantity = null, bool $post = true): mixed
    {
        $drafts = app(SalesDeliveryService::class);
        $delivery = $drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_order_item_id' => $item->id, 'quantity' => $quantity ?? $item->quantity])->all()], (string) Str::uuid7());

        return $post ? app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $drafts->revision($delivery)) : $delivery;
    }

    private function payload(mixed $delivery, string $quantity = '1'): array
    {
        return ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => '2026-10-17',
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity])->all()];
    }

    private function invoice(mixed $delivery, string $quantity = '1'): SalesInvoice
    {
        return $this->service->create($this->actor, $this->payload($delivery, $quantity), (string) Str::uuid7());
    }

    private function postInvoice(SalesInvoice $invoice): SalesInvoice
    {
        return $this->service->post($this->actor, $invoice, $this->service->revision($invoice));
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected operation rejection.');
    }

    private function invariants(): array
    {
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states',
            'fifo_layers', 'fifo_layer_consumptions', 'sales_stock_reservations', 'sales_reservation_consumptions', 'payments'];
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }

    private function scoped(array $permissions, ?Location $branch = null): User
    {
        $branch ??= $this->branch;
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        app(LocationContext::class)->setFor($user, $branch->id);
        $role = Role::create(['name' => 'invoice-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function returned(mixed $delivery, string $quantity = '3'): mixed
    {
        $service = app(SalesReturnService::class);
        $return = $service->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => '2026-10-20',
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'restock'])->all()], (string) Str::uuid7());
        $approver = $this->scoped(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $service->revision($return));
        app(LocationContext::class)->setFor($this->actor, null);
        $this->actingAs($this->actor);

        return app(SalesReturnPostingService::class)->post($this->actor, $return, $service->revision($return));
    }

    private function creditPayload(mixed $return, SalesInvoice $invoice, ?string $quantity = null): array
    {
        return ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id, 'credit_date' => '2026-10-20', 'reason' => 'Customer return',
            'items' => $return->postedAllocations()->where('sales_invoice_id', $invoice->id)->orderBy('id')->get()->map(fn ($row): array => [
                'sales_return_posted_allocation_id' => $row->id, 'quantity' => $quantity ?? $row->quantity])->all()];
    }

    private function draftCredit(mixed $return, SalesInvoice $invoice, ?string $quantity = null): SalesCreditNote
    {
        return app(SalesCreditNoteService::class)->create($this->actor, $this->creditPayload($return, $invoice, $quantity), (string) Str::uuid7());
    }

    private function postCredit(SalesCreditNote $note): SalesCreditNote
    {
        $service = app(SalesCreditNoteService::class);

        return $service->post($this->actor, $note, $service->revision($note));
    }

    private function fixture(): array
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));

        return [$this->returned($delivery), $invoice, $delivery];
    }

    private function financialSnapshot(): array
    {
        return collect(['sales_credit_notes', 'sales_credit_note_items', 'document_numbers', 'document_number_sequences', 'auth_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function paidSale(string $type = 'cash'): array
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));
        $method = PaymentMethod::factory()->create(['type' => $type, 'requires_reference' => $type !== 'cash']);
        $account = TreasuryAccount::factory()->create(['type' => $type === 'cash' ? 'branch_cashbox' : 'bank', 'location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $payment = app(CustomerPayments::class)->payInvoice($this->actor, $invoice, ['payment_method_id' => $method->id, 'treasury_account_id' => $account->id,
            'amount' => $invoice->total_amount, 'payment_date' => now()->toDateString(), 'external_reference' => $type === 'cash' ? null : 'CONFIRMED-'.$type], (string) Str::uuid7());

        return [$delivery, $invoice, $payment, $account, $method];
    }

    private function refundData(Payment $payment, SalesCreditNote $credit): array
    {
        return ['payment_id' => $payment->id, 'amount' => $credit->total_amount, 'refund_date' => now()->toDateString(), 'external_reference' => 'REFUND-CONFIRMED', 'reason' => 'Returned shoes'];
    }

    public static function paymentTypes(): array
    {
        return ['cash' => ['cash'], 'card' => ['card'], 'bank transfer' => ['bank_transfer']];
    }

    #[DataProvider('paymentTypes')]
    public function test_full_walk_in_sale_return_credit_and_refund_preserve_stock_and_treasury(string $type): void
    {
        [$delivery, $invoice, $payment, $account, $method] = $this->paidSale($type);
        $this->assertNull($payment->customer_id);
        $pdf = app(SalesInvoicePdfData::class)->prepare($invoice, 'ar', $this->actor);
        $this->assertSame('settled', $pdf['settlement']['payment_status']);
        $this->assertSame($invoice->total_amount, $pdf['settlement']['paid']);
        $this->assertStringContainsString($pdf['paymentLabel']('status.settled'), view('sales::invoices.pdf', $pdf)->render());
        $this->assertSame($invoice->total_amount, app(TreasuryService::class)->balance($this->actor, $account));
        $return = $this->returned($delivery);
        $credit = $this->postCredit($this->draftCredit($return, $invoice));
        $this->assertSame($invoice->total_amount, app(TreasuryService::class)->balance($this->actor, $account));
        $this->assertDatabaseCount('payment_refunds', 0);
        $before = $this->invariants();
        app(PaymentMethods::class)->save($this->actor, ['is_active' => false], $method);
        $service = app(CustomerRefunds::class);
        $key = (string) Str::uuid7();
        $refund = $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, $credit), $key);
        $this->assertSame($refund->id, $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, $credit), $key)->id);
        $this->assertSame($before, $this->invariants());
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $account));
        $this->assertSame('0.0000', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['outstanding']);
        $this->assertSame('300.0000', StockBalance::where('variant_id', $this->variant->id)->value('quantity_on_hand'));
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertDatabaseCount('treasury_entries', 2);
        $this->assertSame($account->id, $refund->treasury_account_id);
        $this->reject(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, $credit), (string) Str::uuid7()));
        $service->recover($this->actor, $refund, (string) Str::uuid7(), 'Verified refund recovery', 'RECOVERY-REF');
        $this->assertSame($invoice->total_amount, app(TreasuryService::class)->balance($this->actor, $account));
        $this->assertSame('posted', $refund->fresh()->status);
    }

    public function test_partial_return_refund_works_from_sales_and_remains_in_invoice_history(): void
    {
        [$delivery, $invoice, $payment, $account] = $this->paidSale();
        $return = $this->returned($delivery, '1');
        $credit = $this->postCredit($this->draftCredit($return, $invoice));
        app()->setLocale('ar');
        $page = Livewire::test(ViewSalesCreditNote::class, ['record' => $credit->uuid])->assertActionVisible('refundCreditNote')
            ->callAction('refundCreditNote', $this->refundData($payment, $credit) + ['operation_key' => (string) Str::uuid7()])
            ->assertHasNoActionErrors()->assertNotified(__('payments.refund_posted'));
        $page->assertSee(PaymentRefund::where('sales_credit_note_id', $credit->id)->sole()->refund_number);
        $this->assertSame((string) BigDecimal::of($invoice->total_amount)->minus($credit->total_amount), app(TreasuryService::class)->balance($this->actor, $account));
        $this->assertSame('298.0000', StockBalance::where('variant_id', $this->variant->id)->value('quantity_on_hand'));
        $this->assertSame('0.0000', app(CustomerFinancials::class)->invoice($this->actor, $invoice)['outstanding']);
        Livewire::test(ViewSalesInvoice::class, ['record' => $invoice->uuid])->assertSee($payment->payment_number);
    }

    public function test_insufficient_funds_roll_back_refund_allocation_changes_and_draft_credit_cannot_refund(): void
    {
        [$delivery, $invoice, $payment, $account] = $this->paidSale();
        $credit = $this->draftCredit($this->returned($delivery), $invoice);
        $service = app(CustomerRefunds::class);
        $this->reject(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, $credit), (string) Str::uuid7()));
        $credit = $this->postCredit($credit);
        $treasury = app(TreasuryService::class);
        $withdrawal = $treasury->createDraft($this->actor, ['type' => 'cash_withdrawal', 'account_id' => $account->id, 'amount' => $payment->amount, 'currency' => 'EGP',
            'effective_date' => now()->toDateString(), 'source_type' => 'external_other', 'source_reference' => 'CASH-COLLECTION', 'reason' => 'Cash collected'], (string) Str::uuid7());
        $treasury->post($this->actor, $withdrawal);
        $allocations = DB::table('payment_allocations')->get()->toJson();
        $this->reject(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, $credit), (string) Str::uuid7()));
        $this->assertSame($allocations, DB::table('payment_allocations')->get()->toJson());
        $this->assertDatabaseCount('payment_refunds', 0);
        $this->assertDatabaseCount('treasury_entries', 2);
    }

    public function test_refund_rejects_another_original_receipt_and_cross_branch_actor(): void
    {
        [$delivery, $invoice, $payment] = $this->paidSale();
        $credit = $this->postCredit($this->draftCredit($this->returned($delivery), $invoice));
        [, , $other] = $this->paidSale();
        $this->reject(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($other, $credit), (string) Str::uuid7()));
        $branch = Location::create(['name' => 'Other refund branch', 'type' => 'branch', 'is_active' => true]);
        $actor = $this->scoped([...SalesPermissions::all(), ...PaymentsPermissions::all(), ...TreasuryPermissions::all()], $branch);
        $this->reject(fn () => app(CustomerRefunds::class)->refundCreditNote($actor, $credit, $this->refundData($payment, $credit), (string) Str::uuid7()), HttpException::class);
        $this->assertDatabaseCount('payment_refunds', 0);
    }
}
