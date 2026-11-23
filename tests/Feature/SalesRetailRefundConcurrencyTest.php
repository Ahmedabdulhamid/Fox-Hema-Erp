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
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
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
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\TestCase;

class SalesRetailRefundConcurrencyTest extends TestCase
{
    use HasConfiguredSalesPricing;

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
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', DB::connection()->getDatabaseName())) {
            throw new \LogicException('Concurrency requires a fresh disposable Treasury schema.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
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

    private function paymentWorkers(array $requests): array
    {
        $start = (string) (microtime(true) + 2);
        $processes = [];
        foreach ($requests as $request) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/CustomerSettlementWorker.php')], base_path(), $request + [
                'CUSTOMER_START' => $start, 'CUSTOMER_ACTOR' => (string) $this->actor->id, 'CUSTOMER_LOCATION' => (string) $this->branch->id]);
            $process->setTimeout(60)->start();
            $processes[] = $process;
        }
        $results = [];
        try {
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }

        return $results;
    }

    public function test_concurrent_sale_payment_replays_and_competing_refunds_are_safe(): void
    {
        $previousFinancialRows = [];
        foreach (['payments', 'payment_refunds', 'treasury_entries'] as $table) {
            $previousFinancialRows[$table] = DB::table($table)->orderBy('id')->get();
        }
        [$delivery, $invoice, $payment, $account] = $this->paidSale();
        $this->assertMatchesRegularExpression('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', DB::connection()->getDatabaseName());
        $data = $payment->only(['payment_method_id', 'treasury_account_id', 'amount', 'external_reference']);
        $data['payment_date'] = $payment->payment_date->format('Y-m-d');
        $request = ['CUSTOMER_MODE' => 'invoice-payment', 'CUSTOMER_INVOICE' => (string) $invoice->id, 'CUSTOMER_KEY' => $payment->idempotency_key, 'CUSTOMER_DATA' => json_encode($data)];
        $results = $this->paymentWorkers([$request, $request]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame([$payment->id, $payment->id], array_column($results, 'id'));
        $credit = $this->postCredit($this->draftCredit($this->returned($delivery), $invoice));
        $data = $this->refundData($payment, $credit);
        $data['amount'] = (string) BigDecimal::of($credit->total_amount)->multipliedBy('0.6')->toScale(4, RoundingMode::Down);
        $requests = array_map(fn () => ['CUSTOMER_MODE' => 'credit-refund', 'CUSTOMER_CREDIT' => (string) $credit->id,
            'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_DATA' => json_encode($data)], [1, 2]);
        $results = $this->paymentWorkers($requests);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $winnerIndex = array_search('ok', array_column($results, 'status'), true);
        $winner = $requests[$winnerIndex];
        $replays = $this->paymentWorkers([$winner, $winner]);
        $this->assertSame(['ok', 'ok'], array_column($replays, 'status'));
        $this->assertSame([$results[$winnerIndex]['id'], $results[$winnerIndex]['id']], array_column($replays, 'id'));
        $this->assertSame(1, Payment::where('source_document_type', 'sales_invoice')->where('source_document_reference', $invoice->uuid)->count());
        $this->assertSame(1, Payment::where('idempotency_key', $payment->idempotency_key)->count());
        $this->assertSame(1, DB::table('payment_refunds')->where('payment_id', $payment->id)->count());
        $this->assertSame(1, DB::table('payment_refunds')->where('sales_credit_note_id', $credit->id)->count());
        $this->assertSame(2, DB::table('treasury_entries')->where('account_id', $account->id)->count());
        $this->assertSame((string) BigDecimal::of($payment->amount)->minus($data['amount']), app(TreasuryService::class)->balance($this->actor, $account));
        foreach ($previousFinancialRows as $table => $rows) {
            $this->assertSame($rows->toJson(), DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->toJson(), 'Unrelated financial fixtures must remain unchanged.');
        }
    }
}
