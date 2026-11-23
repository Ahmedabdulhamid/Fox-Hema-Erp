<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Filament\Pages\PosTerminal;
use App\Modules\POS\Filament\Resources\PosSessions\Pages\ListPosSessions;
use App\Modules\POS\Filament\Resources\PosSessions\Pages\ViewPosSession;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Services\PosCashMovementService;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\TestCase;

class PosCashRefundTest extends TestCase
{
    use HasConfiguredSalesPricing;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    private PosSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', DB::connection()->getDatabaseName())) {
            throw new \LogicException('POS refund tests require the disposable Treasury test schema.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['pos', 'payments', 'sales', 'treasury', 'inventory', 'inventory_costing', 'products', 'purchasing', 'taxes', 'discounts'])
            ->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Checkout branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PosPermissions::all(), ...PaymentsPermissions::all(),
            ...TreasuryPermissions::all(), ...SalesPermissions::all(), ...InventoryPermissions::all(), ...PurchasingPermissions::all()]);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active',
            'is_active' => true, 'has_fixed_selling_price' => true, 'sale_price' => '100']);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, [
            'supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id,
            'order_date' => today()->toDateString(), 'items' => [[
                'product_id' => $product->id, 'variant_id' => $this->variant->id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                'quantity' => '10', 'unit_price' => '20',
            ]],
        ], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [[
            'id' => $receipt->items()->firstOrFail()->id, 'quantity' => '10', 'unit_cost' => '20',
        ]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));

        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $devices = app(PosDeviceManagement::class);
        $device = $devices->register($this->actor, ['name' => 'Checkout register', 'location_id' => $this->branch->id]);
        $devices->configurePaymentMethod($this->actor, $device, (int) $this->method->id, (int) $this->cash->id);
        $this->shift = app(PosShiftService::class)->open($this->actor, (int) $device->id, (int) $this->cash->id, '0', (string) Str::uuid7());
    }

    /** @return array{SalesCreditNote, Payment} */
    private function creditedSale(): array
    {
        $sale = app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '1']],
            'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']], 'cash_received' => '100',
        ]);
        $returns = app(SalesReturnService::class);
        $line = $sale->result_payload['return_lines'][0];
        $return = $returns->create($this->actor, ['sales_order_id' => $sale->sales_order_id, 'sales_invoice_id' => $sale->sales_invoice_id,
            'return_date' => today()->toDateString(), 'items' => [[
                'sales_delivery_item_id' => $line['sales_delivery_item_id'], 'sales_invoice_item_id' => $line['sales_invoice_item_id'],
                'quantity' => '1', 'disposition' => 'restock',
            ]]], (string) Str::uuid7());
        $approver = User::factory()->create(['is_active' => true]);
        $approver->locations()->attach($this->branch);
        $approver->givePermissionTo(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));
        $return = app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
        $credits = app(SalesCreditNoteService::class);
        $credit = $credits->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $sale->sales_invoice_id,
            'credit_date' => today()->toDateString(), 'reason' => 'POS return',
            'items' => $return->postedAllocations()->get()->map(fn ($allocation): array => [
                'sales_return_posted_allocation_id' => $allocation->id, 'quantity' => $allocation->quantity,
            ])->all()], (string) Str::uuid7());

        return [$credits->post($this->actor, $credit, $credits->revision($credit)), Payment::where('pos_transaction_id', $sale->id)->firstOrFail()];
    }

    /** @return array<string, mixed> */
    private function refundData(Payment $payment, string $amount = '100'): array
    {
        return ['payment_id' => $payment->id, 'amount' => $amount, 'refund_date' => today()->toDateString(), 'reason' => 'Cash handed to customer', 'external_reference' => null];
    }

    /** @return array<string, string> */
    private function financialSnapshot(): array
    {
        return collect(['payment_refunds', 'payment_allocations', 'treasury_operations', 'treasury_entries', 'pos_cash_movements', 'auth_events', 'document_numbers', 'document_number_sequences'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function assertRejectedWithoutChanges(Closure $operation): void
    {
        $before = $this->financialSnapshot();
        try {
            $operation();
            $this->fail('The unsafe refund was accepted.');
        } catch (ValidationException|HttpException $exception) {
            if ($exception instanceof HttpException) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    #[TestWith(['ar'])]
    #[TestWith(['en'])]
    public function test_terminal_exposes_and_runs_the_online_cash_refund_action(string $locale): void
    {
        app()->setLocale($locale);
        [$credit, $payment] = $this->creditedSale();
        $terminal = Livewire::test(PosTerminal::class)->assertActionVisible('posCashRefund');
        $html = $terminal->html();
        preg_match('/<nav class="fox-pos-navigation"[\s\S]*?<\/nav>/', $html, $navigation);
        preg_match('/<header class="fox-pos-toolbar"[\s\S]*?<\/header>/', $html, $header);
        $this->assertStringContainsString(__('pos.cash_refund.action'), $navigation[0]);
        $this->assertStringNotContainsString(__('pos.cash_refund.action'), $header[0]);
        $this->assertLessThan(strpos($navigation[0], __('pos.cash_refund.action')), strpos($navigation[0], 'data-pos-nav="returns"'));
        $this->assertLessThan(strpos($navigation[0], 'data-pos-nav="history"'), strpos($navigation[0], __('pos.cash_refund.action')));
        $this->assertStringContainsString('data-pos-online-only', $navigation[0]);
        $this->assertStringContainsString('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', $html);
        $terminal
            ->callAction('posCashRefund', data: [
                'operation_key' => (string) Str::uuid7(), 'source' => $credit->id.':'.$payment->id,
                'amount' => '100', 'reason' => 'Confirmed cash refund',
            ])->assertHasNoActionErrors();
        $this->assertSame(1, PaymentRefund::where('payment_id', $payment->id)->where('status', 'posted')->count());
    }

    public function test_close_shift_form_submits_after_cash_refund(): void
    {
        [$credit, $payment] = $this->creditedSale();
        app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7());
        app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), [
            'items' => [['variant_id' => $this->variant->id, 'quantity' => '1']],
            'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']], 'cash_received' => '100',
        ]);
        $before = $this->financialSnapshot();
        Livewire::test(ViewPosSession::class, ['record' => $this->shift->uuid])
            ->callAction('closeShift', data: ['counted_cash' => '100', 'tender_counts' => [$this->method->id => '100']])
            ->assertHasNoActionErrors()
            ->assertNotified(__('pos.notifications.shift_closed'));
        $this->assertSame('closed', $this->shift->refresh()->status);
        $this->assertSame('100.0000', $this->shift->expected_cash);
        $this->assertSame('0.0000', $this->shift->cash_difference);
        $this->assertDatabaseHas('pos_session_tenders', ['pos_session_id' => $this->shift->id,
            'payment_method_id' => $this->method->id, 'expected_amount' => '100.0000', 'verified_amount' => '100.0000', 'difference_amount' => '0.0000']);
        $this->assertSame($before['treasury_entries'], $this->financialSnapshot()['treasury_entries']);
        $this->assertSame($before['payment_refunds'], $this->financialSnapshot()['payment_refunds']);
    }

    public function test_close_shift_form_displays_service_validation_errors(): void
    {
        $before = $this->financialSnapshot();
        Livewire::test(ViewPosSession::class, ['record' => $this->shift->uuid])
            ->callAction('closeShift', data: ['counted_cash' => 'invalid'])
            ->assertHasActionErrors(['counted_cash'])
            ->assertNotified(__('pos.notifications.shift_close_failed'));
        $this->assertSame('open', $this->shift->refresh()->status);
        $this->assertNull($this->shift->closing_key);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_close_shift_form_displays_required_field_errors(): void
    {
        Livewire::test(ViewPosSession::class, ['record' => $this->shift->uuid])
            ->callAction('closeShift', data: ['counted_cash' => ''])
            ->assertHasActionErrors(['counted_cash' => 'required']);
        $this->assertSame('open', $this->shift->refresh()->status);
        $this->assertNull($this->shift->closing_key);
    }

    public function test_close_shift_form_includes_opening_cash_and_reports_discrepancy(): void
    {
        $shifts = app(PosShiftService::class);
        $shifts->close($this->actor, (int) $this->shift->id, ['counted_cash' => '0', 'tender_counts' => []], (string) Str::uuid7());
        $treasury = app(TreasuryService::class);
        $deposit = $treasury->createDraft($this->actor, ['type' => 'cash_deposit', 'account_id' => $this->cash->id,
            'amount' => '25290', 'currency' => 'EGP', 'effective_date' => today()->toDateString(), 'source_type' => 'external_other',
            'source_reference' => 'Test opening float', 'reason' => 'Fund disposable drawer'], (string) Str::uuid7());
        $treasury->post($this->actor, $deposit);
        $this->shift = $shifts->open($this->actor, (int) $this->shift->pos_device_id, (int) $this->cash->id, '25290', (string) Str::uuid7());
        [$credit, $payment] = $this->creditedSale();
        app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7());
        $this->assertSame('25290.0000', $shifts->expectedClosingCash($this->actor, (int) $this->shift->id));
        $this->assertSame('0.0000', $shifts->expectedTenders($this->actor, (int) $this->shift->id)[0]['expected_amount']);
        $before = $this->financialSnapshot();
        $page = Livewire::test(ViewPosSession::class, ['record' => $this->shift->uuid])
            ->mountAction('closeShift')
            ->assertFormFieldExists('counted_cash', fn (TextInput $field): bool => str_contains($field->toHtml(), e(__('pos.fields.closing_cash_help', [
                'expected' => '25290.0000', 'opening' => '25290.0000',
            ]))))
            ->setActionData(['counted_cash' => '0', 'tender_counts' => [$this->method->id => '0']])
            ->callMountedAction()
            ->assertHasActionErrors(['difference_reason'])
            ->assertNotified(__('pos.notifications.shift_close_failed'));
        $this->assertSame(__('pos.validation.discrepancy_reason_required'), $page->instance()->getErrorBag()->first('mountedActions.0.data.difference_reason'));
        $this->assertSame('open', $this->shift->refresh()->status);
        $this->assertNull($this->shift->closing_key);
        $this->assertSame($before, $this->financialSnapshot());
        $page->setActionData(['counted_cash' => '25290', 'tender_counts' => [$this->method->id => '0']])->callMountedAction()
            ->assertHasNoActionErrors()->assertNotified(__('pos.notifications.shift_closed'));
        $this->assertSame('closed', $this->shift->refresh()->status);
        $this->assertSame('0.0000', $this->shift->cash_difference);
    }

    public function test_close_shift_table_action_reports_non_field_validation_failures(): void
    {
        $before = $this->financialSnapshot();
        Livewire::test(ListPosSessions::class)
            ->callAction(TestAction::make('closeShift')->table($this->shift), data: ['counted_cash' => '0', 'closing_key' => 'invalid'])
            ->assertHasActionErrors(['key'])
            ->assertNotified(__('pos.notifications.shift_close_failed'));
        $this->assertSame('open', $this->shift->refresh()->status);
        $this->assertNull($this->shift->closing_key);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_cash_refund_posts_one_withdrawal_and_reconciles_the_drawer(): void
    {
        [$credit, $payment] = $this->creditedSale();
        $stockMovements = DB::table('stock_movements')->count();
        $entries = DB::table('treasury_entries')->count();
        $operations = DB::table('treasury_operations')->count();
        $service = app(CustomerRefunds::class);
        $key = (string) Str::uuid7();
        $refund = $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key);
        $replay = $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key);
        $this->assertSame($refund->id, $replay->id);
        $this->assertSame('posted', $refund->status);
        $this->assertSame((int) $this->shift->id, (int) $refund->pos_session_id);
        $this->assertSame((int) $payment->pos_transaction_id, (int) $refund->pos_transaction_id);
        $this->assertSame((int) $payment->treasury_account_id, (int) $refund->treasury_account_id);
        $this->assertSame(1, PaymentRefund::where('payment_id', $payment->id)->count());
        $this->assertSame(0, DB::table('pos_cash_movements')->where('pos_session_id', $this->shift->id)->count());
        $this->assertSame($stockMovements, DB::table('stock_movements')->count());
        $this->assertSame($entries + 1, DB::table('treasury_entries')->count());
        $this->assertSame($operations + 1, DB::table('treasury_operations')->count());
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertSame('0.0000', app(PosShiftService::class)->expectedTenders($this->actor, (int) $this->shift->id)[0]['expected_amount']);
        $this->assertSame('0.0000', app(CustomerFinancials::class)->available($payment, $this->actor));
        $this->assertRejectedWithoutChanges(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment, '99'), $key));
        $this->assertRejectedWithoutChanges(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7()));
        $this->assertDatabaseHas('treasury_entries', ['operation_id' => $refund->treasury_operation_id, 'account_id' => $this->cash->id, 'direction' => 'out', 'amount' => '100.0000']);
    }

    #[TestWith(['closed'])]
    #[TestWith(['closing'])]
    #[TestWith(['cashier'])]
    #[TestWith(['branch'])]
    #[TestWith(['device'])]
    #[TestWith(['drawer'])]
    #[TestWith(['receipt_account'])]
    #[TestWith(['receipt_method'])]
    public function test_invalid_pos_context_rolls_back_every_financial_change(string $case): void
    {
        [$credit, $payment] = $this->creditedSale();
        $actor = $this->actor;
        if ($case === 'closed') {
            DB::table('pos_sessions')->where('id', $this->shift->id)->update(['status' => 'closed', 'closed_at' => now(), 'active_device_id' => null, 'active_cashier_id' => null, 'active_cash_account_id' => null]);
        } elseif ($case === 'closing') {
            DB::table('pos_sessions')->where('id', $this->shift->id)->update(['closing_started_at' => now()]);
        } elseif ($case === 'cashier') {
            $actor = User::factory()->create(['is_active' => true]);
            $actor->locations()->attach($this->branch);
            $actor->givePermissionTo([...PosPermissions::all(), ...PaymentsPermissions::all(), ...SalesPermissions::all(), ...TreasuryPermissions::all()]);
        } elseif ($case === 'branch') {
            setPermissionsTeamId(Location::create(['name' => 'Other refund branch', 'type' => 'branch', 'is_active' => true])->id);
        } elseif ($case === 'device') {
            DB::table('pos_devices')->where('id', $this->shift->pos_device_id)->update(['is_active' => false, 'revoked_at' => now()]);
        } elseif ($case === 'drawer') {
            $drawer = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
            DB::table('pos_sessions')->where('id', $this->shift->id)->update(['cash_treasury_account_id' => $drawer->id]);
        } elseif ($case === 'receipt_account') {
            $payment->treasury_account_id = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id])->id;
            DB::table('payments')->where('id', $payment->id)->update(['treasury_account_id' => $payment->treasury_account_id]);
        } else {
            DB::table('payments')->where('id', $payment->id)->update(['payment_method_id' => PaymentMethod::factory()->create(['type' => 'cash'])->id]);
        }
        $this->assertRejectedWithoutChanges(fn () => app(CustomerRefunds::class)->refundCreditNote($actor, $credit, $this->refundData($payment), (string) Str::uuid7()));
    }

    #[TestWith(['payments.refunds.create'])]
    #[TestWith(['payments.refunds.post'])]
    #[TestWith(['payments.allocations.release'])]
    #[TestWith(['pos.returns'])]
    #[TestWith([TreasuryPermissions::POST])]
    public function test_missing_authorization_cannot_refund(string $permission): void
    {
        [$credit, $payment] = $this->creditedSale();
        $this->actor->revokePermissionTo($permission);
        $this->assertRejectedWithoutChanges(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7()));
    }

    public function test_insufficient_drawer_balance_rolls_back_allocations_and_refund_draft(): void
    {
        [$credit, $payment] = $this->creditedSale();
        app(PosCashMovementService::class)->post($this->actor, (int) $this->shift->id, 'cash_out', '100', 'Float transfer', (string) Str::uuid7());
        $this->assertRejectedWithoutChanges(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7()));
    }

    public function test_refund_exceeding_credit_is_rejected_without_partial_records(): void
    {
        [$credit, $payment] = $this->creditedSale();
        $this->assertRejectedWithoutChanges(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment, '101'), (string) Str::uuid7()));
    }

    public function test_failure_after_ledger_insert_rolls_back_and_can_retry(): void
    {
        [$credit, $payment] = $this->creditedSale();
        $key = (string) Str::uuid7();
        $event = 'eloquent.created: '.TreasuryEntry::class;
        Event::listen($event, function (): never {
            throw ValidationException::withMessages(['refund' => 'Simulated ledger completion failure.']);
        });
        try {
            $this->assertRejectedWithoutChanges(fn () => app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key));
        } finally {
            Event::forget($event);
        }
        $refund = app(CustomerRefunds::class)->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key);
        $this->assertSame('posted', $refund->status);
        $this->assertSame(1, PaymentRefund::where('payment_id', $payment->id)->count());
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    public function test_unrelated_treasury_withdrawal_stays_blocked(): void
    {
        $this->creditedSale();
        $this->assertRejectedWithoutChanges(function (): void {
            $treasury = app(TreasuryService::class);
            DB::transaction(function () use ($treasury): void {
                $operation = $treasury->createDraft($this->actor, ['type' => 'cash_withdrawal', 'account_id' => $this->cash->id,
                    'amount' => '1', 'currency' => 'EGP', 'effective_date' => today()->toDateString(), 'source_type' => 'external_other', 'source_reference' => 'unrelated', 'reason' => 'Not a refund'], (string) Str::uuid7());
                $treasury->post($this->actor, $operation);
            });
        });
    }

    public function test_posted_refund_replays_after_shift_close_but_new_refunds_do_not(): void
    {
        [$credit, $payment] = $this->creditedSale();
        $key = (string) Str::uuid7();
        $service = app(CustomerRefunds::class);
        $refund = $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key);
        $shift = app(PosShiftService::class)->close($this->actor, (int) $this->shift->id, [
            'counted_cash' => '0', 'tender_counts' => [$this->method->id => '0'],
        ], (string) Str::uuid7());
        $this->assertSame('closed', $shift->status);
        $this->assertSame($refund->id, $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), $key)->id);
        $this->assertRejectedWithoutChanges(fn () => $service->refundCreditNote($this->actor, $credit, $this->refundData($payment), (string) Str::uuid7()));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_concurrent_refund_requests_post_once(bool $sameKey): void
    {
        [$credit, $payment] = $this->creditedSale();
        $config = DB::connection()->getConfig();
        $base = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/pos-refund-worker-no-cache.php'),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'CUSTOMER_ACTOR' => (string) $this->actor->id, 'CUSTOMER_LOCATION' => (string) $this->branch->id,
            'CUSTOMER_MODE' => 'credit-refund', 'CUSTOMER_CREDIT' => (string) $credit->id, 'CUSTOMER_DATA' => json_encode($this->refundData($payment), JSON_THROW_ON_ERROR),
            'CUSTOMER_START' => (string) (microtime(true) + 1)];
        $key = (string) Str::uuid7();
        $jobs = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $job = new Process([PHP_BINARY, base_path('tests/Support/CustomerSettlementWorker.php')], base_path(),
                    ['CUSTOMER_KEY' => $sameKey || $index === 0 ? $key : (string) Str::uuid7()] + $base);
                $job->setTimeout(60)->start();
                $jobs[] = $job;
            }
            $results = [];
            foreach ($jobs as $job) {
                $job->wait();
                $this->assertTrue($job->isSuccessful(), $job->getErrorOutput().$job->getOutput());
                $results[] = json_decode($job->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($sameKey ? ['ok', 'ok'] : ['ok', 'rejected'], collect($results)->pluck('status')->sort()->values()->all());
            if ($sameKey) {
                $this->assertSame($results[0]['id'], $results[1]['id']);
            }
            $this->assertSame(1, PaymentRefund::where('payment_id', $payment->id)->count());
            $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        } finally {
            foreach ($jobs as $job) {
                if ($job->isRunning()) {
                    $job->stop();
                }
            }
        }
    }
}
