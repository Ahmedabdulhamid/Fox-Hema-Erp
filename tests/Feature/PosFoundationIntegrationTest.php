<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Data\PosContext;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Services\PosCashMovementService;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PosFoundationIntegrationTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    private PosSession $shift;

    private PosTransaction $transaction;

    private PosContext $context;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['pos', 'payments', 'sales', 'treasury', 'inventory', 'products', 'taxes', 'discounts'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'POS test branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...TreasuryPermissions::all(), ...SalesPermissions::all(), ...PosPermissions::all()]);
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $this->method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false, 'available_in_pos' => true]);
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $this->shift = PosSession::factory()->create(['pos_device_id' => $device->id, 'location_id' => $this->branch->id, 'opened_by' => $this->actor->id, 'cash_treasury_account_id' => $this->cash->id]);
        $this->transaction = PosTransaction::factory()->create(['pos_session_id' => $this->shift->id, 'sync_status' => 'processing']);
        $this->context = PosContext::fromTransaction($this->actor, $this->transaction);
        DB::table('pos_device_payment_methods')->insert(['uuid' => (string) Str::uuid7(), 'pos_device_id' => $device->id, 'payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id, 'is_active' => true]);
        $order = SalesOrder::factory()->create(['uuid' => $this->context->orderSubmissionId(), 'location_id' => $this->branch->id, 'channel' => 'pos', 'created_by' => $this->actor->id]);
        $this->invoice = SalesInvoice::factory()->create(['sales_order_id' => $order->id, 'location_id' => $this->branch->id, 'total_amount' => '100', 'subtotal' => '100', 'created_by' => $this->actor->id]);
        DB::table('sales_invoices')->where('id', $this->invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id]);
        $this->invoice->refresh();
    }

    public function test_shift_closing_accepts_plain_decimal_strings_and_rejects_unsupported_drawer_formats(): void
    {
        $service = app(PosShiftService::class);
        $key = (string) Str::uuid7();
        foreach (['32796', '32796.0000', '5499.0000', '0', '0.0000', 32796, 0] as $amount) {
            $result = $service->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => $amount, 'tender_counts' => [$this->method->id => '5499.0000']], $key);
            $this->assertSame('closing', $result->status);
            $this->assertNull($result->expected_cash);
        }
        foreach (['-1', '-0.0001', 'EGP 32796', '32796 EGP', '32,796.0000', '32796,0000',
            ' 32796.0000 ', "32796.0000\u{200F}", '٣٢٧٩٦.٠٠٠٠', 'NaN', 'Infinity', '1e4', '', '1.2.3', '0.00001', NAN, INF] as $amount) {
            try {
                $service->close($this->actor, (int) $this->shift->id,
                    ['counted_cash' => $amount, 'tender_counts' => [$this->method->id => '5499.0000']], $key);
                $this->fail('Unsupported drawer amount was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('counted_cash', $exception->errors());
                $this->assertArrayNotHasKey('tender_counts.'.$this->method->id, $exception->errors());
            }
        }
        try {
            $service->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => '32796.0000', 'tender_counts' => [$this->method->id => 'EGP 5499']], $key);
            $this->fail('Invalid payment-method count was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tender_counts.'.$this->method->id, $exception->errors());
            $this->assertArrayNotHasKey('counted_cash', $exception->errors());
        }
    }

    private function data(): array
    {
        return ['payment_method_id' => $this->method->id, 'treasury_account_id' => $this->cash->id, 'amount' => '100', 'payment_date' => now()->toDateString()];
    }

    public function test_pos_receipt_posts_existing_financial_records_once_and_replays_after_close(): void
    {
        $service = app(CustomerPayments::class);
        $key = (string) Str::uuid7();
        $payment = $service->payInvoice($this->actor, $this->invoice, $this->data(), $key, $this->context);
        $this->assertSame($this->transaction->id, $payment->pos_transaction_id);
        $this->assertSame($this->shift->id, $payment->pos_session_id);
        $this->assertSame('payments', $payment->origin_module);
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->shift->update(['status' => 'closed', 'closed_at' => now()]);
        $this->transaction->update(['sync_status' => 'completed']);
        $this->assertSame($payment->id, $service->payInvoice($this->actor, $this->invoice, $this->data(), $key, $this->context)->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertDatabaseCount('treasury_entries', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_context_is_part_of_idempotency_and_cannot_be_added_after_posting(): void
    {
        $service = app(CustomerPayments::class);
        $key = (string) Str::uuid7();
        $payment = $service->payInvoice($this->actor, $this->invoice, $this->data(), $key, $this->context);
        $this->reject(fn () => $service->payInvoice($this->actor, $this->invoice, $this->data(), $key));
        $this->reject(fn () => $service->payInvoice($this->actor, $this->invoice, array_replace($this->data(), ['amount' => '99']), $key, $this->context));
        $payment->pos_session_id = null;
        $this->reject(fn () => $payment->saveQuietly());
        $this->assertSame($this->shift->id, $payment->fresh()->pos_session_id);
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    public function test_generic_payment_and_treasury_entry_points_cannot_adopt_pos_receipts(): void
    {
        $service = app(CustomerPayments::class);
        $draft = $service->createDraft($this->actor, $this->data(), (string) Str::uuid7(), $this->invoice, $this->context);
        $this->reject(fn () => app(PaymentService::class)->post($this->actor, $draft));
        $payment = $service->post($this->actor, $draft);
        $this->reject(fn () => app(TreasuryService::class)->createPaymentDraft($this->actor, $this->cash, $payment, (string) Str::uuid7(), now()->toDateString(), 'Adoption'));
        $this->reject(fn () => app(PaymentService::class)->reverse($this->actor, $payment, (string) Str::uuid7(), 'Reverse'));
        $this->assertDatabaseCount('treasury_entries', 1);
    }

    /** @return array<string, array{string}> */
    public static function invalidContexts(): array
    {
        return array_combine($cases = ['closing', 'revoked', 'cashier', 'branch', 'permission', 'module', 'method', 'configuration', 'invoice', 'treasury_permission', 'inactive_cashier', 'wrong_drawer'], array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_context_rolls_back_all_financial_writes(string $case): void
    {
        match ($case) {
            'closing' => $this->shift->update(['status' => 'closing']),
            'revoked' => $this->shift->posDevice->update(['revoked_at' => now()]),
            'cashier' => $this->transaction->update(['cashier_id' => User::factory()->create()->id]),
            'branch' => setPermissionsTeamId(Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true])->id),
            'permission' => $this->actor->revokePermissionTo(PosPermissions::CHECKOUT),
            'module' => Module::where('code', 'pos')->update(['is_active' => false]),
            'method' => $this->method->update(['available_in_pos' => false]),
            'configuration' => DB::table('pos_device_payment_methods')->update(['is_active' => false]),
            'invoice' => DB::table('sales_orders')->where('id', $this->invoice->sales_order_id)->update(['channel' => 'manual']),
            'treasury_permission' => $this->actor->revokePermissionTo(TreasuryPermissions::POST),
            'inactive_cashier' => $this->actor->update(['is_active' => false]),
            'wrong_drawer' => $this->shift->update(['cash_treasury_account_id' => TreasuryAccount::factory()->create(['location_id' => $this->branch->id])->id]),
        };
        try {
            app(CustomerPayments::class)->payInvoice($this->actor, $this->invoice, $this->data(), (string) Str::uuid7(), $this->context);
            $this->fail('Invalid POS context accepted.');
        } catch (ValidationException|HttpException|AuthorizationException) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('payment_allocations', 0);
            $this->assertDatabaseCount('treasury_entries', 0);
        }
    }

    public function test_pos_order_uses_existing_pricing_and_reservations_with_channel_set_before_confirmation(): void
    {
        $transaction = PosTransaction::factory()->create(['pos_session_id' => $this->shift->id, 'sync_status' => 'processing']);
        $context = PosContext::fromTransaction($this->actor, $transaction);
        $product = Product::factory()->create(['status' => 'active', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'sale_price' => '100']);
        $this->configureSalesPricing($variant);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id, 'quantity_on_hand' => '10', 'quantity_reserved' => '0', 'quantity_available' => '10']);
        $data = ['location_id' => $this->branch->id, 'items' => [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => '1']]];
        $service = app(SalesOrderService::class);
        $order = $service->createForPos($this->actor, $data, $context);
        $this->assertSame('pos', $order->channel);
        $this->assertSame($order->id, $service->createForPos($this->actor, $data, $context)->id);
        $this->assertNull($transaction->fresh()->sales_order_id);
        $confirmed = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order, $service->revision($order));
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('pos', $confirmed->channel);
        $this->assertSame('1.0000', StockBalance::where('variant_id', $variant->id)->first()->quantity_reserved);
        $manual = $service->create($this->actor, $data, (string) Str::uuid7());
        $this->assertSame('manual', $manual->channel);
        $this->reject(fn () => $service->create($this->actor, $data + ['channel' => 'pos'], (string) Str::uuid7()));
    }

    public function test_shift_opening_allows_concurrent_branch_cashiers_and_replays_idempotently(): void
    {
        $cashier = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $cashier->locations()->attach($this->branch);
        $cashier->givePermissionTo([...PaymentsPermissions::all(), ...TreasuryPermissions::all(), ...PosPermissions::all()]);
        $drawer = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $cashier->id]);
        $device = app(PosDeviceManagement::class)->register($cashier, [
            'name' => 'Second cashier device',
            'location_id' => $this->branch->id,
        ]);
        app(PosDeviceManagement::class)->configurePaymentMethod($cashier, $device, $this->method->id, $drawer->id);

        $key = (string) Str::uuid7();
        $service = app(PosShiftService::class);
        $treasury = app(TreasuryService::class);
        $treasury->post($cashier, $treasury->createDraft($cashier, [
            'type' => 'cash_deposit', 'account_id' => $drawer->id, 'amount' => '25',
            'effective_date' => today()->toDateString(), 'source_type' => 'external_other',
            'source_reference' => 'OPENING-FLOAT', 'reason' => 'Fund shift drawer',
        ], (string) Str::uuid7()));
        $opened = $service->open($cashier, (int) $device->id, (int) $drawer->id, '25', $key);
        $replayed = $service->open($cashier, (int) $device->id, (int) $drawer->id, '25', $key);

        $this->assertSame($opened->id, $replayed->id);
        $this->assertSame(2, PosSession::where('location_id', $this->branch->id)->whereIn('status', ['open', 'closing'])->count());
        $this->reject(fn () => $service->open($cashier, (int) $device->id, (int) $drawer->id, '25', (string) Str::uuid7()));
    }

    public function test_shift_closing_waits_for_incomplete_transactions_then_reconciles_posted_tender(): void
    {
        app(CustomerPayments::class)->payInvoice($this->actor, $this->invoice, $this->data(), (string) Str::uuid7(), $this->context);
        $key = (string) Str::uuid7();
        $payload = ['counted_cash' => '100', 'tender_counts' => [$this->method->id => '100']];
        $service = app(PosShiftService::class);

        $pending = $service->close($this->actor, (int) $this->shift->id, $payload, $key);
        $this->assertSame('closing', $pending->status);
        $this->assertNull($pending->expected_cash);
        $this->assertNull($pending->closing_payload_hash);

        $this->transaction->update(['sync_status' => 'completed']);
        $closed = $service->close($this->actor, (int) $this->shift->id, $payload, $key);

        $this->assertSame('closed', $closed->status);
        $this->assertSame('100.0000', $closed->expected_cash);
        $this->assertSame('0.0000', $closed->cash_difference);
        $this->assertSame('100.0000', $closed->tenders()->firstOrFail()->expected_amount);
    }

    public function test_cash_movement_posts_once_and_reconciles_only_posted_treasury_evidence(): void
    {
        $service = app(PosCashMovementService::class);
        $key = (string) Str::uuid7();
        $movement = $service->post($this->actor, (int) $this->shift->id, 'cash_in', '30', 'Cash float', $key);
        $replayed = $service->post($this->actor, (int) $this->shift->id, 'cash_in', '30', 'Cash float', $key);

        $this->assertSame($movement->id, $replayed->id);
        $this->assertNotNull($movement->treasury_operation_id);
        $this->assertSame('posted', $movement->treasuryOperation->status);
        $this->assertSame('30.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_operations', 1);

        $this->transaction->update(['sync_status' => 'completed']);
        $closed = app(PosShiftService::class)->close(
            $this->actor,
            (int) $this->shift->id,
            ['counted_cash' => '30', 'tender_counts' => []],
            (string) Str::uuid7(),
        );

        $this->assertSame('closed', $closed->status);
        $this->assertSame('30.0000', $closed->expected_cash);
    }

    #[TestWith(['open'])]
    #[TestWith(['closing'])]
    public function test_general_treasury_posting_cannot_change_an_active_drawer(string $status): void
    {
        $this->shift->update(['status' => $status]);
        $other = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $treasury = app(TreasuryService::class);
        foreach ([
            ['type' => 'cash_deposit', 'account_id' => $this->cash->id],
            ['type' => 'cash_withdrawal', 'account_id' => $this->cash->id],
            ['type' => 'transfer', 'account_id' => $this->cash->id, 'destination_account_id' => $other->id],
            ['type' => 'transfer', 'account_id' => $other->id, 'destination_account_id' => $this->cash->id],
        ] as $data) {
            $draft = $treasury->createDraft($this->actor, $data + [
                'amount' => '10', 'effective_date' => today()->toDateString(),
                'source_type' => 'external_other', 'source_reference' => 'pos-cash-movement:'.Str::uuid7(),
                'reason' => 'General Treasury movement',
            ], (string) Str::uuid7());
            try {
                $treasury->post($this->actor, $draft);
                $this->fail('Active drawer accepted a general Treasury movement.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('account', $exception->errors());
            }
            $this->assertSame('draft', $draft->fresh()->status);
        }
        $this->assertDatabaseCount('treasury_entries', 0);

        $allowed = $treasury->createDraft($this->actor, [
            'type' => 'cash_deposit', 'account_id' => $other->id, 'amount' => '10',
            'effective_date' => today()->toDateString(), 'source_type' => 'external_other', 'source_reference' => 'OTHER-1', 'reason' => 'Other drawer',
        ], (string) Str::uuid7());
        $this->assertSame('posted', $treasury->post($this->actor, $allowed)->status);
    }

    #[TestWith(['open'])]
    #[TestWith(['closed'])]
    public function test_pos_cash_movement_cannot_be_reversed_from_treasury(string $status): void
    {
        $movement = app(PosCashMovementService::class)->post($this->actor, (int) $this->shift->id,
            'cash_in', '30', 'Float', (string) Str::uuid7());
        if ($status === 'closed') {
            $this->transaction->update(['sync_status' => 'completed']);
            app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => '30', 'tender_counts' => []], (string) Str::uuid7());
        }
        $this->reject(fn () => app(TreasuryService::class)->reverse($this->actor,
            $movement->treasuryOperation, (string) Str::uuid7(), 'Independent reversal'));
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertSame('30.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    #[TestWith(['open'])]
    #[TestWith(['closing'])]
    public function test_active_drawer_cannot_be_deactivated_but_can_be_renamed(string $status): void
    {
        $this->shift->update(['status' => $status]);
        $accounts = app(TreasuryAccounts::class);
        try {
            $accounts->save($this->actor, ['is_active' => false], $this->cash);
            $this->fail('Active shift drawer was deactivated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('is_active', $exception->errors());
        }
        $this->assertTrue($this->cash->fresh()->is_active);
        $this->assertSame('Renamed drawer', $accounts->save($this->actor, ['name' => 'Renamed drawer'], $this->cash)->name);
    }

    public function test_closed_drawer_allows_general_posting_and_deactivation(): void
    {
        $this->transaction->update(['sync_status' => 'completed']);
        app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
            ['counted_cash' => '0', 'tender_counts' => []], (string) Str::uuid7());
        $treasury = app(TreasuryService::class);
        $draft = $treasury->createDraft($this->actor, [
            'type' => 'cash_deposit', 'account_id' => $this->cash->id, 'amount' => '10',
            'effective_date' => today()->toDateString(), 'source_type' => 'external_other', 'source_reference' => 'CLOSED-1', 'reason' => 'After closing',
        ], (string) Str::uuid7());
        $this->assertSame('posted', $treasury->post($this->actor, $draft)->status);
        $this->assertFalse(app(TreasuryAccounts::class)->save($this->actor, ['is_active' => false], $this->cash)->is_active);
    }

    public function test_shift_opening_requires_recorded_funds_and_opening_replay_does_not_recheck_changed_balance(): void
    {
        $this->transaction->update(['sync_status' => 'completed']);
        $shifts = app(PosShiftService::class);
        $shifts->close($this->actor, (int) $this->shift->id, ['counted_cash' => '0', 'tender_counts' => []], (string) Str::uuid7());
        $this->reject(fn () => $shifts->open($this->actor, (int) $this->shift->pos_device_id,
            (int) $this->cash->id, '100', (string) Str::uuid7()));
        $treasury = app(TreasuryService::class);
        $treasury->post($this->actor, $treasury->createDraft($this->actor, [
            'type' => 'cash_deposit', 'account_id' => $this->cash->id, 'amount' => '100',
            'effective_date' => today()->toDateString(), 'source_type' => 'external_other',
            'source_reference' => 'FUNDED-FLOAT', 'reason' => 'Fund opening float',
        ], (string) Str::uuid7()));
        $this->reject(fn () => $shifts->open($this->actor, (int) $this->shift->pos_device_id,
            (int) $this->cash->id, '0', (string) Str::uuid7()));
        $key = (string) Str::uuid7();
        $opened = $shifts->open($this->actor, (int) $this->shift->pos_device_id, (int) $this->cash->id, '100', $key);
        app(PosCashMovementService::class)->post($this->actor, (int) $opened->id, 'cash_out', '10', 'Expense', (string) Str::uuid7());
        $this->assertSame('90.0000', $treasury->balance($this->actor, $this->cash));
        $this->assertSame($opened->id, $shifts->open($this->actor, (int) $this->shift->pos_device_id,
            (int) $this->cash->id, '100', $key)->id);
        $closed = $shifts->close($this->actor, (int) $opened->id, ['counted_cash' => '90', 'tender_counts' => []], (string) Str::uuid7());
        $this->assertSame('90.0000', $closed->expected_cash);
        $this->assertSame('0.0000', $closed->cash_difference);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_waiting_close_can_recount_or_explain_a_difference_before_reconciliation(bool $explain): void
    {
        $shifts = app(PosShiftService::class);
        $key = (string) Str::uuid7();
        $pending = $shifts->close($this->actor, (int) $this->shift->id, ['counted_cash' => '1', 'tender_counts' => []], $key);
        $this->assertSame('closing', $pending->status);
        $this->assertNull($pending->closing_payload_hash);
        $this->assertNull($pending->expected_cash);
        $this->transaction->update(['sync_status' => 'rejected']);
        $this->reject(fn () => $shifts->close($this->actor, (int) $this->shift->id,
            ['counted_cash' => '0', 'tender_counts' => []], (string) Str::uuid7()));
        $this->reject(fn () => $shifts->close($this->actor, (int) $this->shift->id,
            ['counted_cash' => '1', 'tender_counts' => []], $key));
        $data = ['counted_cash' => $explain ? '1' : '0', 'tender_counts' => []];
        if ($explain) {
            $data['difference_reason'] = 'Cash count discrepancy';
        }
        $closed = $shifts->close($this->actor, (int) $this->shift->id, $data, $key);
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->closing_payload_hash);
        $this->assertSame($closed->id, $shifts->close($this->actor, (int) $this->shift->id, $data, $key)->id);
        $data['counted_cash'] = '2';
        $this->reject(fn () => $shifts->close($this->actor, (int) $this->shift->id, $data, $key));
    }

    public function test_legacy_waiting_close_with_premature_hash_can_be_reconciled(): void
    {
        $key = (string) Str::uuid7();
        $this->shift->update(['status' => 'closing', 'closing_key' => $key,
            'closing_started_at' => now(), 'closing_payload_hash' => str_repeat('a', 64)]);
        $this->transaction->update(['sync_status' => 'rejected']);
        $closed = app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
            ['counted_cash' => '0', 'tender_counts' => []], $key);
        $this->assertSame('closed', $closed->status);
        $this->assertNotSame(str_repeat('a', 64), $closed->closing_payload_hash);
    }

    public function test_reconciled_closing_data_remains_frozen_until_manager_approval(): void
    {
        $this->transaction->update(['sync_status' => 'completed']);
        $this->actor->revokePermissionTo(PosPermissions::SHIFTS_APPROVE);
        $shifts = app(PosShiftService::class);
        $key = (string) Str::uuid7();
        $data = ['counted_cash' => '1', 'tender_counts' => [], 'difference_reason' => 'Extra cash'];
        $pending = $shifts->close($this->actor, (int) $this->shift->id, $data, $key);
        $this->assertSame('closing', $pending->status);
        $this->assertNotNull($pending->closing_payload_hash);
        $data['counted_cash'] = '0';
        $this->reject(fn () => $shifts->close($this->actor, (int) $this->shift->id, $data, $key));
        $this->actor->givePermissionTo(PosPermissions::SHIFTS_APPROVE);
        $this->assertSame('closed', $shifts->approveClosing($this->actor, (int) $this->shift->id, 'Verified cash difference')->status);
    }

    public function test_database_rejects_duplicate_open_shift(): void
    {
        $this->expectException(QueryException::class);
        PosSession::factory()->create(['pos_device_id' => $this->shift->pos_device_id, 'location_id' => $this->branch->id, 'opened_by' => $this->actor->id, 'cash_treasury_account_id' => $this->cash->id]);
    }

    public function test_database_rejects_payment_from_another_shift(): void
    {
        $draft = app(CustomerPayments::class)->createDraft($this->actor, $this->data(), (string) Str::uuid7(), $this->invoice, $this->context);
        $otherShift = PosSession::factory()->create(['status' => 'closed']);
        $this->expectException(QueryException::class);
        DB::table('payments')->where('id', $draft->id)->update(['pos_session_id' => $otherShift->id]);
    }

    public function test_failure_after_receipt_posting_rolls_back_treasury_and_payment(): void
    {
        $service = app(CustomerPayments::class);
        try {
            DB::transaction(function () use ($service): void {
                $service->payInvoice($this->actor, $this->invoice, $this->data(), (string) Str::uuid7(), $this->context);
                throw new \RuntimeException('Simulated checkout failure');
            });
            $this->fail('Expected outer failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated checkout failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('treasury_entries', 0);
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Protected operation accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}
