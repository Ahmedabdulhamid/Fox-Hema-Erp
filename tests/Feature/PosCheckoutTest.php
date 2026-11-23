<?php

namespace Tests\Feature;

use App\Http\Middleware\BindPosOfflineContext;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Filament\Pages\PosTerminal;
use App\Modules\POS\Filament\Resources\PosSessions\Pages\ListPosSessions;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\POS\Services\LocalOfflineSaleRecovery;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosDeviceManagement;
use App\Modules\POS\Services\PosOfflineAuthorizationLease;
use App\Modules\POS\Services\PosOfflineOutboxes;
use App\Modules\POS\Services\PosOfflineReturnSyncService;
use App\Modules\POS\Services\PosOfflineSaleSyncService;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\POS\Services\PosShiftService;
use App\Modules\POS\Services\PosTerminalCatalog;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private TreasuryAccount $cash;

    private PaymentMethod $method;

    private PosSession $shift;

    protected function setUp(): void
    {
        parent::setUp();
        /** Initial schema creation can resolve numbering before its registry table exists. */
        $this->app->forgetInstance(DocumentNumberService::class);
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['items' => [['variant_id' => $this->variant->id, 'quantity' => '1']],
            'tenders' => [['payment_method_id' => $this->method->id, 'amount' => '100']], 'cash_received' => '120'];
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        return collect(['pos_transactions', 'sales_orders', 'sales_deliveries', 'sales_invoices', 'sales_stock_reservations',
            'payments', 'payment_allocations', 'treasury_operations', 'treasury_entries', 'stock_balances',
            'stock_movements', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers', 'fifo_layer_consumptions'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_checkout_delivers_invoices_settles_and_replays_once_after_closing(): void
    {
        $service = app(PosCheckoutService::class);
        $key = (string) Str::uuid7();
        $transaction = $service->checkout($this->actor, (int) $this->shift->id, $key, $this->payload());
        $this->assertSame('completed', $transaction->sync_status);
        $this->assertSame('20.0000', $transaction->result_payload['cash_change']);
        $this->assertSame('pos', $transaction->salesOrder->channel);
        $this->assertSame('posted', $transaction->salesInvoice->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('sales_deliveries', ['sales_order_id' => $transaction->sales_order_id, 'status' => 'posted']);
        $balance = StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
        $this->assertSame('9.0000', $balance->quantity_on_hand);
        $this->assertSame('0.0000', $balance->quantity_reserved);
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $beforeReplay = $this->snapshot();
        $this->assertSame($transaction->id, $service->checkout($this->actor, (int) $this->shift->id, $key, $this->payload())->id);
        $this->assertSame($beforeReplay, $this->snapshot());

        app(PosShiftService::class)->close($this->actor, (int) $this->shift->id, [
            'counted_cash' => '100', 'tender_counts' => [$this->method->id => '100'],
        ], (string) Str::uuid7());
        $this->assertSame($transaction->id, $service->checkout($this->actor, (int) $this->shift->id, $key, $this->payload())->id);
        $conflicting = $this->payload();
        $conflicting['cash_received'] = '150';
        try {
            $service->checkout($this->actor, (int) $this->shift->id, $key, $conflicting);
            $this->fail('Conflicting replay accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_transaction_id', $exception->errors());
        }
        $this->assertSame($beforeReplay, $this->snapshot());
    }

    public function test_offline_sale_sync_posts_once_and_retries_without_duplicate_financial_or_inventory_records(): void
    {
        $scope = [
            'branch_id' => (int) $this->branch->id,
            'device_id' => (int) $this->shift->pos_device_id,
            'session_id' => (int) $this->shift->id,
            'cashier_id' => (int) $this->actor->id,
        ];
        $cart = [$this->variant->id => ['quantity' => '1', 'discount_id' => null]];
        $envelope = [
            'client_transaction_id' => (string) Str::uuid7(),
            'outbox_id' => (string) Str::uuid7(),
            'scope' => $scope,
            'sale' => $this->payload(),
            'quote' => app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id, $cart),
        ];
        $sync = app(PosOfflineSaleSyncService::class);
        app(PosOfflineOutboxes::class)->register($this->actor, $this->shift->posDevice, $scope, $envelope['outbox_id']);
        $envelope['quote']['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip([
            'variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total',
        ])), $envelope['quote']['items']);
        $beforeSync = $this->snapshot();
        foreach (['0.001', '0.0002', '1.5', '2.25', '1.00001', '0', '-1'] as $quantity) {
            foreach (['sale', 'quote'] as $boundary) {
                $invalid = $envelope;
                $invalid[$boundary]['items'][0]['quantity'] = $quantity;
                try {
                    $sync->synchronize($this->actor, $this->shift->posDevice, $invalid);
                    $this->fail('A non-whole POS sale quantity was accepted.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey($boundary.'.items.0.quantity', $exception->errors());
                }
            }
            $payload = $this->payload();
            $payload['items'][0]['quantity'] = $quantity;
            try {
                app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), $payload);
                $this->fail('A non-whole online checkout quantity was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items.0.quantity', $exception->errors());
            }
            try {
                app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id, [$this->variant->id => ['quantity' => $quantity]]);
                $this->fail('A non-whole POS cart quantity was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cart.'.$this->variant->id.'.quantity', $exception->errors());
            }
        }
        $this->assertSame($beforeSync, $this->snapshot());

        $first = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);
        $beforeRetry = $this->snapshot();
        $retry = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);

        $this->assertSame('completed', $first['status']);
        $this->assertNotSame($beforeSync, $beforeRetry);
        $this->assertSame($first['transaction_uuid'], $retry['transaction_uuid']);
        $this->assertSame($first['result'], $retry['result']);
        $this->assertSame($beforeRetry, $this->snapshot());
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(
            DB::table('stock_movements')->count() - count(json_decode($beforeSync['stock_movements'], true, flags: JSON_THROW_ON_ERROR)),
            1,
        );
    }

    #[TestWith(['active'])]
    #[TestWith(['expired'])]
    #[TestWith(['disabled'])]
    #[TestWith(['unassigned'])]
    #[TestWith(['missing'])]
    #[TestWith(['changed_value'])]
    public function test_offline_discount_sync_preserves_saved_sale_and_requires_current_eligibility(string $state): void
    {
        $this->variant->product()->update(['sale_price' => '299']);
        $discount = Discount::factory()->create(['value' => '15', 'usage' => 'sales']);
        $this->variant->product->discounts()->attach($discount);
        $sale = $this->registeredOfflineSale();
        $sale['sale']['items'][0]['discount_id'] = (string) $discount->id;
        $quote = app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id,
            [$this->variant->id => ['quantity' => '1', 'discount_id' => $discount->id]]);
        $quote['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip([
            'variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total',
        ])), $quote['items']);
        $sale['quote'] = $quote;
        $sale['sale']['tenders'][0]['amount'] = $sale['sale']['cash_received'] = '254.1500';
        $this->assertSame('254.1500', $quote['total_amount']);
        $catalog = app(PosTerminalCatalog::class)->offlineCatalogPage($this->actor, (int) $this->branch->id, 1);
        $product = collect($catalog['items'])->firstWhere('id', $this->variant->product_id);
        $this->assertSame((int) $discount->id, $product['discounts'][0]['id']);
        $saved = json_encode($sale, JSON_THROW_ON_ERROR);

        if ($state === 'expired') {
            $discount->update(['ends_at' => now()->subSecond()]);
        } elseif ($state === 'disabled') {
            $discount->update(['is_active' => false]);
        } elseif ($state === 'unassigned') {
            $this->variant->product->discounts()->detach($discount);
        } elseif ($state === 'missing') {
            $discount->delete();
        } elseif ($state === 'changed_value') {
            $discount->update(['value' => '10']);
        }
        $before = $this->snapshot();
        $sync = app(PosOfflineSaleSyncService::class);
        if ($state !== 'active') {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
                    $this->fail('An invalid or changed discount must not post the saved sale.');
                } catch (ValidationException $exception) {
                    $field = $state === 'changed_value' ? 'cart' : 'cart.'.$this->variant->id.'.discount_id';
                    $this->assertArrayHasKey($field, $exception->errors());
                    if ($state !== 'changed_value') {
                        $this->assertSame([__('pos.validation.invalid_discount')], $exception->errors()[$field]);
                    }
                }
                $this->assertSame($before, $this->snapshot());
                $this->assertSame($saved, json_encode($sale, JSON_THROW_ON_ERROR));
            }
            $this->assertNull(DB::table('pos_offline_outboxes')->where('uuid', $sale['outbox_id'])->value('sealed_at'));
            $this->assertSame($before, $this->snapshot());

            return;
        }
        $first = $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
        $this->assertSame('completed', $first['status']);
        $transaction = PosTransaction::where('client_transaction_id', $sale['client_transaction_id'])->firstOrFail();
        $this->assertSame('254.1500', $transaction->salesInvoice->total_amount);
        $discount->update(['is_active' => false]);
        $posted = $this->snapshot();
        $this->assertSame($first, $sync->synchronize($this->actor, $this->shift->posDevice, $sale));
        $this->assertSame($posted, $this->snapshot());
        $this->assertSame($saved, json_encode($sale, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function registeredOfflineSale(): array
    {
        $scope = ['branch_id' => (int) $this->branch->id, 'device_id' => (int) $this->shift->pos_device_id,
            'session_id' => (int) $this->shift->id, 'cashier_id' => (int) $this->actor->id];
        $outboxId = (string) Str::uuid7();
        app(PosOfflineOutboxes::class)->register($this->actor, $this->shift->posDevice, $scope, $outboxId);
        $quote = app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id,
            [$this->variant->id => ['quantity' => '1', 'discount_id' => null]]);
        $quote['items'] = array_map(fn (array $item): array => array_intersect_key($item, array_flip([
            'variant_id', 'quantity', 'discount_id', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total',
        ])), $quote['items']);

        return ['client_transaction_id' => (string) Str::uuid7(), 'outbox_id' => $outboxId,
            'scope' => $scope, 'sale' => $this->payload(), 'quote' => $quote];
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_local_test_cancellation_preserves_evidence_replay_finances_and_manifest(bool $firstSaleExists): void
    {
        $sale = $this->registeredOfflineSale();
        DB::table('pos_offline_outboxes')->where('uuid', $sale['outbox_id'])->update(['uuid' => LocalOfflineSaleRecovery::OUTBOX]);
        $sale['outbox_id'] = LocalOfflineSaleRecovery::OUTBOX;
        $sync = app(PosOfflineSaleSyncService::class);
        $records = [];
        foreach ([LocalOfflineSaleRecovery::MANIFEST[0], LocalOfflineSaleRecovery::MANIFEST[2]] as $id) {
            $completed = $sale;
            $completed['client_transaction_id'] = $id;
            if ($id !== LocalOfflineSaleRecovery::MANIFEST[0] || $firstSaleExists) {
                $sync->synchronize($this->actor, $this->shift->posDevice, $completed);
            }
            $completed['status'] = 'completed';
            $completed['scopeKey'] = implode(':', $sale['scope']);
            if ($id === LocalOfflineSaleRecovery::MANIFEST[0]) {
                unset($completed['outbox_id']);
                $completed['created_at'] = '2026-11-08T19:44:34.278Z';
                $completed['synced_at'] = '2026-11-08T19:45:29.598Z';
                $completed['result'] = ['sales_order_id' => 999990, 'sales_invoice_id' => 999990,
                    'sales_invoice_uuid' => (string) Str::uuid7(), 'invoice_number' => 'HISTORICAL-ACK'];
            }
            $records[] = $completed;
        }
        $rejected = $sale;
        $rejected['client_transaction_id'] = LocalOfflineSaleRecovery::SALE;
        $rejected['status'] = 'conflict';
        $rejected['scopeKey'] = implode(':', $sale['scope']);
        $rejected['created_at'] = '2026-11-08T19:45:15.697Z';
        $rejected['original_sale'] = $sale['sale'];
        $rejected['original_sale']['cash_received'] = '54.1500';
        $rejected['error_history'] = [['status' => 422, 'message' => 'Unavailable discount']];
        unset($rejected['outbox_id']);
        $records[] = $rejected;
        $evidence = ['scope' => $sale['scope'], 'outbox' => ['id' => LocalOfflineSaleRecovery::OUTBOX,
            'registered' => true, 'frozen' => true, 'sealed' => false, 'transaction_ids' => LocalOfflineSaleRecovery::MANIFEST],
            'transactions' => $records];
        $recovery = app(LocalOfflineSaleRecovery::class);
        $reason = 'Explicitly approved cancellation of the disposable local test sale.';
        $before = $this->snapshot();
        $this->assertNull($recovery->cancel($this->actor, $evidence, $reason));
        $this->assertSame($before, $this->snapshot());
        $unprivileged = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        try {
            $recovery->cancel($unprivileged, $evidence, $reason, true);
            $this->fail('Unprivileged cancellation must be refused.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $cancelled = $recovery->cancel($this->actor, $evidence, $reason, true);
        $this->assertTrue($recovery->isTerminal($cancelled, LocalOfflineSaleRecovery::OUTBOX));
        $this->assertSame($evidence, $cancelled->request_payload['evidence']);
        $this->assertSame('rejected', $cancelled->sync_status);
        $this->assertNull($cancelled->sales_invoice_id);
        $after = $this->snapshot();
        $this->assertSame(array_diff_key($before, ['pos_transactions' => true]), array_diff_key($after, ['pos_transactions' => true]));
        $this->assertSame($cancelled->id, $recovery->cancel($this->actor, $evidence, $reason, true)->id);
        $this->assertSame($after, $this->snapshot());
        $ack = $sync->synchronize($this->actor, $this->shift->posDevice, [
            'scope' => $sale['scope'], 'outbox_id' => LocalOfflineSaleRecovery::OUTBOX,
            'client_transaction_id' => LocalOfflineSaleRecovery::SALE, 'sale' => $sale['sale'], 'quote' => $sale['quote'],
        ]);
        $this->assertSame('cancelled', $ack['status']);
        $competing = new \PDO('mysql:host='.DB::connection()->getConfig('host').';port='.DB::connection()->getConfig('port')
            .';dbname='.DB::connection()->getDatabaseName(), DB::connection()->getConfig('username'), DB::connection()->getConfig('password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]);
        $competing->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $competing->exec("UPDATE pos_offline_outboxes SET transaction_ids = '[]' WHERE uuid = '".LocalOfflineSaleRecovery::OUTBOX."'");
            $this->fail('A competing connection must not change an uncommitted frozen manifest.');
        } catch (\PDOException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1]);
        }
        if (! $firstSaleExists) {
            $readOnlyLookup = $sale;
            $readOnlyLookup['client_transaction_id'] = LocalOfflineSaleRecovery::MANIFEST[0];
            $readOnlyLookup['acknowledgement_only'] = true;
            try {
                $sync->synchronize($this->actor, $this->shift->posDevice, $readOnlyLookup);
                $this->fail('A cancellation acknowledgement lookup cannot post an orphaned sale.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame($after, $this->snapshot());
        }
        try {
            app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, LocalOfflineSaleRecovery::SALE, $sale['sale']);
            $this->fail('Cancelled UUID cannot become a sale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_transaction_id', $exception->errors());
        }
        $outboxes = app(PosOfflineOutboxes::class);
        if ($firstSaleExists) {
            try {
                $recovery->archive($this->actor, $evidence, 'Refuse archival of an existing authoritative posting.', true);
                $this->fail('A posted transaction cannot be archived.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame('200.0000', app(PosShiftService::class)->expectedClosingCash($this->actor, (int) $this->shift->id));
            app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => '200', 'tender_counts' => [$this->method->id => '200']], (string) Str::uuid7());
            $this->assertSame('closed', $this->shift->fresh()->status);
            $nextShift = app(PosShiftService::class)->open($this->actor, (int) $this->shift->pos_device_id,
                (int) $this->cash->id, '200', (string) Str::uuid7());
            $nextScope = [...$sale['scope'], 'session_id' => (int) $nextShift->id];
            $nextOutbox = (string) Str::uuid7();
            $outboxes->register($this->actor, $nextShift->posDevice, $nextScope, $nextOutbox);
            $this->assertNotSame((int) $this->shift->id, (int) $nextShift->id);
            $this->assertDatabaseHas('pos_offline_outboxes', ['uuid' => LocalOfflineSaleRecovery::OUTBOX, 'pos_session_id' => $this->shift->id]);
        } else {
            $this->assertNull(DB::table('pos_offline_outboxes')->where('uuid', LocalOfflineSaleRecovery::OUTBOX)->value('sealed_at'));
            $archiveReason = 'Explicit authorization to archive the historical orphan without any current financial posting.';
            $archiveBefore = $this->snapshot();
            $this->assertNull($recovery->archive($this->actor, $evidence, $archiveReason));
            $this->assertSame($archiveBefore, $this->snapshot());
            try {
                $recovery->archive($unprivileged, $evidence, $archiveReason, true);
                $this->fail('Archival requires supervisor permission.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->shift->update(['opened_by' => $unprivileged->id]);
            try {
                $recovery->archive($this->actor, $evidence, $archiveReason, true);
                $this->fail('Archival must reject changed ownership.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->shift->update(['opened_by' => $this->actor->id]);
            $rawEvidence = json_encode($evidence, JSON_THROW_ON_ERROR);
            $archived = $recovery->archive($this->actor, $evidence, $archiveReason, true, $rawEvidence)->fresh();
            $this->assertTrue($recovery->isTerminal($archived, LocalOfflineSaleRecovery::OUTBOX));
            $this->assertSame($evidence, $archived->request_payload['evidence']);
            $this->assertSame($rawEvidence, $archived->request_payload['raw_evidence']);
            $this->assertSame($archived->id, $recovery->archive($this->actor, $evidence, $archiveReason, true)->id);
            $this->assertSame(1, DB::table('auth_events')->where('event', 'pos_local_historical_orphan_archived')->count());
            $this->assertSame(array_diff_key($archiveBefore, ['pos_transactions' => true]), array_diff_key($this->snapshot(), ['pos_transactions' => true]));
            $readOnlyLookup['acknowledgement_only'] = true;
            $this->assertSame('archived', $sync->synchronize($this->actor, $this->shift->posDevice, $readOnlyLookup)['status']);
            try {
                app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, LocalOfflineSaleRecovery::ORPHAN, $sale['sale']);
                $this->fail('An archived UUID must never post a sale.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('client_transaction_id', $exception->errors());
            }
            $this->assertSame(LocalOfflineSaleRecovery::MANIFEST, json_decode(DB::table('pos_offline_outboxes')->where('uuid', LocalOfflineSaleRecovery::OUTBOX)->value('transaction_ids'), true));
            $this->assertSame('100.0000', app(PosShiftService::class)->expectedClosingCash($this->actor, (int) $this->shift->id));
            app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => '100', 'tender_counts' => [$this->method->id => '100']], (string) Str::uuid7());
            $this->assertSame('closed', $this->shift->fresh()->status);
            $this->app->instance('env', 'production');
            $this->assertFalse($recovery->isTerminal($archived, LocalOfflineSaleRecovery::OUTBOX));
            try {
                $recovery->archive($this->actor, $evidence, $archiveReason, true);
                $this->fail('Production archival is forbidden.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->app->instance('env', 'testing');
        }
        $this->app->instance('env', 'production');
        $this->assertFalse($recovery->isTerminal($cancelled, LocalOfflineSaleRecovery::OUTBOX));
        $this->app->instance('env', 'testing');
    }

    public function test_offline_posting_cannot_be_adopted_by_another_registered_outbox(): void
    {
        $sale = $this->registeredOfflineSale();
        $sync = app(PosOfflineSaleSyncService::class);
        $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
        $before = $this->snapshot();
        $other = (string) Str::uuid7();
        app(PosOfflineOutboxes::class)->register($this->actor, $this->shift->posDevice, $sale['scope'], $other);
        $sale['outbox_id'] = $other;
        try {
            $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
            $this->fail('An acknowledged transaction cannot move between outboxes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outbox', $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_shift_closes_with_pending_sales_and_late_sync_preserves_closing_snapshot(bool $countedIncludesPending): void
    {
        $sale = $this->registeredOfflineSale();
        $sale['client_occurred_at'] = now()->toIso8601String();
        $this->travel(2)->seconds();
        $closed = app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
            ['counted_cash' => $countedIncludesPending ? '100' : '0', 'tender_counts' => [], 'difference_reason' => 'Offline sale remains saved.'], (string) Str::uuid7());
        $this->assertSame('closed', $closed->status);
        $originalClose = $closed->fresh()->getAttributes();
        $tenders = DB::table('pos_session_tenders')->where('pos_session_id', $closed->id)->get()->toJson();
        $nextShift = $countedIncludesPending ? null : app(PosShiftService::class)->open($this->actor,
            (int) $closed->pos_device_id, (int) $this->cash->id, '0', (string) Str::uuid7());
        $this->travel(2)->days();
        $sync = app(PosOfflineSaleSyncService::class);
        $first = $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
        $this->assertSame('completed', $first['status']);
        $this->assertSame($originalClose, $closed->fresh()->getAttributes());
        $this->assertSame($tenders, DB::table('pos_session_tenders')->where('pos_session_id', $closed->id)->get()->toJson());
        $posted = $this->snapshot();
        $this->assertSame($first, $sync->synchronize($this->actor, $this->shift->posDevice, $sale));
        $this->assertSame($posted, $this->snapshot());
        if ($nextShift !== null) {
            $this->assertSame('open', $nextShift->fresh()->status);
            $this->assertSame('0.0000', app(PosShiftService::class)->expectedClosingCash($this->actor, (int) $nextShift->id));
        }
        $summary = app(PosShiftService::class)->lateReconciliation($this->actor, (int) $closed->id);
        $this->assertSame(1, $summary['late_sales']);
        $this->assertSame('100.0000', $summary['late_amount']);
        $this->assertSame($countedIncludesPending ? '0.0000' : '-100.0000', $summary['cash_difference']);
        $this->assertSame(! $countedIncludesPending, $summary['requires_review']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('sales_invoices', 1);
        $this->assertDatabaseHas('pos_transactions', ['client_transaction_id' => $sale['client_transaction_id'],
            'pos_session_id' => $closed->id, 'cashier_id' => $this->actor->id, 'pos_device_id' => $closed->pos_device_id]);
        $invalid = array_replace($sale, ['client_transaction_id' => (string) Str::uuid7(), 'client_occurred_at' => now()->toIso8601String()]);
        try {
            $sync->synchronize($this->actor, $this->shift->posDevice, $invalid);
            $this->fail('New sales after closure cannot be backfilled.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($posted, $this->snapshot());
    }

    public function test_interrupted_offline_posting_rolls_back_and_keeps_the_outbox_blocking_until_retry(): void
    {
        $sale = $this->registeredOfflineSale();
        $before = $this->snapshot();
        $interrupt = true;
        Event::listen('eloquent.updated: '.PosTransaction::class, function (PosTransaction $transaction) use (&$interrupt): void {
            if ($interrupt && $transaction->sync_status === 'completed') {
                throw new \RuntimeException('Interrupted offline posting');
            }
        });
        $sync = app(PosOfflineSaleSyncService::class);
        try {
            $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
            $this->fail('Expected posting interruption.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Interrupted offline posting', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('pos_offline_outboxes', ['uuid' => $sale['outbox_id'], 'sealed_at' => null]);
        $interrupt = false;
        $this->assertSame('completed', $sync->synchronize($this->actor, $this->shift->posDevice, $sale)['status']);
        $posted = $this->snapshot();
        $sync->synchronize($this->actor, $this->shift->posDevice, $sale);
        $this->assertSame($posted, $this->snapshot());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_offline_outbox_endpoints_preserve_branch_and_cashier_authorization(): void
    {
        $sale = $this->registeredOfflineSale();
        $outboxUrl = route('pos.offline.outbox', ['device' => $this->shift->pos_device_id]);
        app(LocationContext::class)->setFor($this->actor, (int) $this->branch->id);
        $this->postJson($outboxUrl, ['action' => 'register', 'scope' => $sale['scope'], 'outbox_id' => $sale['outbox_id']])->assertOk();
        foreach (['freeze', 'seal'] as $removedAction) {
            $this->postJson($outboxUrl, ['action' => $removedAction, 'scope' => $sale['scope'], 'outbox_id' => $sale['outbox_id']])
                ->assertUnprocessable()->assertJsonValidationErrors('action');
        }
        $missingId = $sale;
        unset($missingId['outbox_id']);
        $this->postJson(route('pos.offline.sync', ['device' => $this->shift->pos_device_id]), $missingId)
            ->assertUnprocessable()->assertJsonValidationErrors('outbox_id');
        $otherBranch = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($otherBranch);
        app(LocationContext::class)->setFor($this->actor, (int) $otherBranch->id);
        $this->actor->givePermissionTo(PosPermissions::all());
        $before = $this->snapshot();
        $this->postJson(route('pos.offline.sync', ['device' => $this->shift->pos_device_id]), $sale)->assertForbidden();
        foreach (['register', 'verify'] as $action) {
            $this->postJson($outboxUrl, ['action' => $action, 'scope' => $sale['scope'],
                'outbox_id' => $sale['outbox_id'], 'transaction_ids' => []])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot());
        app(LocationContext::class)->setFor($this->actor, (int) $this->branch->id);
        $forged = $sale;
        $forged['scope']['cashier_id'] = User::factory()->create()->id;
        $this->postJson(route('pos.offline.sync', ['device' => $this->shift->pos_device_id]), $forged)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($outboxUrl, ['action' => 'verify', 'scope' => $sale['scope'],
            'outbox_id' => $sale['outbox_id'], 'transaction_ids' => []])->assertOk();
        $otherCashier = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $otherCashier->locations()->attach($otherBranch);
        $this->actingAs($otherCashier);
        app(LocationContext::class)->setFor($otherCashier, (int) $otherBranch->id);
        $otherCashier->givePermissionTo(PosPermissions::all());
        $this->postJson(route('pos.offline.sync', ['device' => $this->shift->pos_device_id]), $sale)->assertForbidden();
        $forged = $sale;
        $forged['scope']['cashier_id'] = (int) $otherCashier->id;
        $this->postJson(route('pos.offline.sync', ['device' => $this->shift->pos_device_id]), $forged)->assertNotFound();
        foreach (['register', 'verify'] as $action) {
            $this->postJson($outboxUrl, ['action' => $action, 'scope' => $forged['scope'],
                'outbox_id' => $sale['outbox_id'], 'transaction_ids' => []])->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_offline_context_is_bound_to_authorized_login_branch_device_and_shift(): void
    {
        $scope = ['branch_id' => (int) $this->branch->id, 'device_id' => (int) $this->shift->pos_device_id,
            'session_id' => (int) $this->shift->id, 'cashier_id' => (int) $this->actor->id];
        $request = Request::create('/pos-context-test');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $this->actor);
        $request->session()->put(LocationContext::SESSION_KEY, $this->branch->id);
        $context = app(BindPosOfflineContext::class);
        $first = $context->bind($request, $scope);
        $this->assertNotNull($first);
        $originalUuid = $this->shift->uuid;
        DB::table('pos_sessions')->where('id', $this->shift->id)->update(['uuid' => (string) Str::uuid7()]);
        $this->assertNotSame($first, $context->identity($request), 'A recreated database session with reused numeric IDs must invalidate its old context.');
        DB::table('pos_sessions')->where('id', $this->shift->id)->update(['uuid' => $originalUuid]);
        $response = $context->handle($request, fn () => response('OK'));
        $this->assertSame($first, $response->headers->getCookies()[0]->getValue());
        $request->session()->migrate();
        $this->assertNotSame($first, $context->identity($request));
        foreach (['branch_id', 'device_id', 'session_id', 'cashier_id'] as $field) {
            $forged = $scope;
            $forged[$field] += 10000;
            $this->assertThrows(fn () => $context->bind($request, $forged), HttpException::class);
        }
        $request->session()->put(LocationContext::SESSION_KEY, $this->branch->id + 10000);
        $this->assertNull($context->identity($request));
        $request->session()->put(LocationContext::SESSION_KEY, $this->branch->id);
        $other = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $request->setUserResolver(fn () => $other);
        $this->assertNull($context->identity($request));
        $request->setUserResolver(fn () => null);
        $this->assertNull($context->identity($request));
        $this->assertTrue($context->handle($request, fn () => response('OK'))->headers->getCookies()[0]->isCleared());
        $this->get(PosTerminal::getUrl())->assertOk()->assertPlainCookie('fox_pos_context');
    }

    #[TestWith(['en'])]
    #[TestWith(['ar'])]
    public function test_shift_close_action_accepts_reported_decimal_strings_and_maps_errors_independently(string $locale): void
    {
        app()->setLocale($locale);
        $this->variant->product()->update(['sale_price' => '5499']);
        $sale = $this->payload();
        $sale['tenders'][0]['amount'] = '5499';
        $sale['cash_received'] = '5499';
        app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), $sale);
        $action = TestAction::make('closeShift')->table($this->shift);
        $page = Livewire::test(ListPosSessions::class);
        $page->callAction($action, data: ['closing_key' => (string) Str::uuid7(),
            'counted_cash' => 'EGP 32796', 'tender_counts' => [$this->method->id => '5499.0000']])
            ->assertHasActionErrors(['counted_cash']);
        $page = Livewire::test(ListPosSessions::class);
        $page->callAction($action, data: ['closing_key' => (string) Str::uuid7(),
            'counted_cash' => '32796.0000', 'tender_counts' => [$this->method->id => 'EGP 5499']])
            ->assertHasActionErrors(['tender_counts.'.$this->method->id]);
        $page = Livewire::test(ListPosSessions::class);
        $page->callAction($action, data: ['closing_key' => (string) Str::uuid7(),
            'counted_cash' => '32796.0000', 'tender_counts' => [$this->method->id => '5499.0000'],
            'difference_reason' => 'Decimal validation test'])->assertHasNoActionErrors();
        $this->assertSame('32796.0000', $this->shift->fresh()->counted_cash);
        $this->assertSame('5499.0000', $this->shift->tenders()->where('payment_method_id', $this->method->id)->value('verified_amount'));
        $this->assertSame('5499.0000', $this->shift->fresh()->expected_cash);
        $this->assertSame('27297.0000', $this->shift->fresh()->cash_difference);
    }

    public function test_terminal_quantity_controls_do_not_truncate_or_select_zero(): void
    {
        $terminal = Livewire::test(PosTerminal::class)
            ->call('addProduct', (int) $this->variant->product_id)
            ->call('changeQuantity', (int) $this->variant->id, -1)
            ->assertSet('cart.'.$this->variant->id.'.quantity', 1)
            ->call('changeQuantity', (int) $this->variant->id, 1)
            ->assertSet('cart.'.$this->variant->id.'.quantity', 2);
        $terminal->set('cart.'.$this->variant->id.'.quantity', '1.5')
            ->call('changeQuantity', (int) $this->variant->id, 1)
            ->assertHasErrors(['quantity'])
            ->assertSet('cart.'.$this->variant->id.'.quantity', '1.5');
        $terminal->call('addProduct', (int) $this->variant->product_id)
            ->assertHasErrors(['quantity'])->assertSet('cart.'.$this->variant->id.'.quantity', '1.5');
        $terminal->call('changeQuantity', (int) $this->variant->id, 1.5)->assertStatus(422);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_offline_return_sync_creates_only_an_idempotent_draft_and_rechecks_permissions(): void
    {
        $sale = app(PosCheckoutService::class)->checkout(
            $this->actor,
            (int) $this->shift->id,
            (string) Str::uuid7(),
            $this->payload(),
        );
        $scope = [
            'branch_id' => (int) $this->branch->id,
            'device_id' => (int) $this->shift->pos_device_id,
            'session_id' => (int) $this->shift->id,
            'cashier_id' => (int) $this->actor->id,
        ];
        $authorization = app(PosOfflineAuthorizationLease::class)->issue($this->actor, $scope);
        $this->assertTrue($authorization['can_create_returns']);
        $sourceLine = $sale->result_payload['return_lines'][0];
        $envelope = [
            'client_transaction_id' => (string) Str::uuid7(),
            'source_client_transaction_id' => $sale->client_transaction_id,
            'client_occurred_at' => now()->toIso8601String(),
            'authorization_token' => $authorization['token'],
            'scope' => $scope,
            'return' => [
                'return_date' => today()->toDateString(),
                'reason' => null,
                'notes' => null,
                'items' => [[
                    'sales_delivery_item_id' => $sourceLine['sales_delivery_item_id'],
                    'sales_invoice_item_id' => $sourceLine['sales_invoice_item_id'],
                    'quantity' => '1.0000',
                    'disposition' => 'restock',
                    'notes' => null,
                ]],
            ],
        ];
        $stockMovementCount = DB::table('stock_movements')->count();
        $treasuryEntryCount = DB::table('treasury_entries')->count();
        $creditNoteCount = DB::table('sales_credit_notes')->count();
        $sync = app(PosOfflineReturnSyncService::class);
        $beforeInvalid = $this->snapshot();
        foreach (['0.001', '0.0002', '1.5', '2.25', '1.00001', '0', '-1'] as $quantity) {
            $invalid = $envelope;
            $invalid['return']['items'][0]['quantity'] = $quantity;
            try {
                $sync->synchronize($this->actor, $this->shift->posDevice, $invalid);
                $this->fail('A non-whole POS return quantity was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('return.items.0.quantity', $exception->errors());
            }
        }
        $this->assertSame($beforeInvalid, $this->snapshot());
        $this->assertDatabaseCount('sales_returns', 0);
        $first = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);
        $retry = $sync->synchronize($this->actor, $this->shift->posDevice, $envelope);

        $this->assertSame('completed', $first['status']);
        $this->assertSame($first['transaction_uuid'], $retry['transaction_uuid']);
        $this->assertSame('draft', $first['result']['return_status']);
        $this->assertSame('not_started', $first['result']['refund_status']);
        $this->assertSame('not_posted', $first['result']['inventory_status']);
        $this->assertDatabaseCount('sales_returns', 1);
        $this->assertDatabaseCount('pos_transactions', 2);
        $this->assertSame($stockMovementCount, DB::table('stock_movements')->count());
        $this->assertSame($treasuryEntryCount, DB::table('treasury_entries')->count());
        $this->assertSame($creditNoteCount, DB::table('sales_credit_notes')->count());

        $invalid = $envelope;
        $invalid['client_transaction_id'] = (string) Str::uuid7();
        $invalid['return']['items'][0]['quantity'] = '2.0000';
        try {
            $sync->synchronize($this->actor, $this->shift->posDevice, $invalid);
            $this->fail('A return quantity exceeding the original sale was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('sales_returns', 1);
        }

        $this->actor->revokePermissionTo(PosPermissions::RETURNS);
        $this->assertFalse(app(PosOfflineAuthorizationLease::class)->issue($this->actor, $scope)['can_create_returns']);
        $revoked = $envelope;
        $revoked['client_transaction_id'] = (string) Str::uuid7();
        try {
            $sync->synchronize($this->actor, $this->shift->posDevice, $revoked);
            $this->fail('A revoked POS return permission was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('sales_returns', 1);
    }

    public function test_offline_catalog_is_scoped_and_supplies_configured_payment_methods(): void
    {
        $terminal = Livewire::test(PosTerminal::class);
        $scope = $terminal->instance()->offlineScope;
        $page = $terminal->instance()->offlineCatalogPage(1);
        $this->assertSame($scope, $page['scope']);
        $this->assertSame((int) $this->variant->id, $page['catalog']['items'][0]['variants'][0]['id']);
        $this->assertSame('10.0000', $page['catalog']['items'][0]['variants'][0]['available']);
        $this->assertSame((int) $this->method->id, $page['payment_methods'][0]['id']);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_checkout_prices_discount_and_tax_with_one_accountless_non_cash_method(): void
    {
        $this->configureSalesPricing($this->variant, '10', '14');
        $card = PaymentMethod::factory()->create(['type' => 'card', 'requires_reference' => true, 'available_in_pos' => true]);
        $this->shift->update(['status' => 'closed', 'closed_at' => now()]);
        app(PosDeviceManagement::class)->configurePaymentMethod($this->actor, $this->shift->posDevice, (int) $card->id, null);
        $this->shift = app(PosShiftService::class)->open($this->actor, (int) $this->shift->pos_device_id,
            (int) $this->cash->id, '0', (string) Str::uuid7());
        $discountId = $this->variant->product->discounts()->firstOrFail()->id;
        $quote = app(PosTerminalCatalog::class)->quote($this->actor, (int) $this->branch->id,
            [$this->variant->id => ['quantity' => '1', 'discount_id' => $discountId]]);
        $this->assertSame('102.6000', $quote['total_amount']);
        $payload = $this->payload();
        $payload['items'][0]['discount_id'] = $discountId;
        $payload['tenders'] = [['payment_method_id' => $card->id, 'amount' => '102.60', 'external_reference' => 'CARD-123']];
        $payload['cash_received'] = '0';
        $transaction = app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), $payload);
        $this->assertSame('102.6000', $transaction->salesInvoice->total_amount);
        $this->assertSame('0.0000', $transaction->result_payload['cash_change']);
        $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
        $payment = Payment::where('pos_transaction_id', $transaction->id)->firstOrFail();
        $this->assertNull($payment->treasury_account_id);
        $this->assertDatabaseMissing('treasury_operations', ['payment_id' => $payment->id]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_rejects_a_non_cash_method_disabled_after_the_screen_loaded(): void
    {
        $wallet = PaymentMethod::factory()->create([
            'name' => 'Vodafone Cash',
            'type' => 'wallet',
            'requires_reference' => true,
            'available_in_pos' => true,
        ]);
        $management = app(PosDeviceManagement::class);
        $management->configurePaymentMethod($this->actor, $this->shift->posDevice, (int) $wallet->id, null);
        $payload = $this->payload();
        $payload['tenders'] = [[
            'payment_method_id' => $wallet->id,
            'amount' => '100',
            'external_reference' => 'WALLET-REF-001',
        ]];
        $payload['cash_received'] = '0';
        $management->configurePaymentMethod($this->actor, $this->shift->posDevice, (int) $wallet->id, null, false);
        $before = $this->snapshot();

        try {
            app(PosCheckoutService::class)->checkout(
                $this->actor,
                (int) $this->shift->id,
                (string) Str::uuid7(),
                $payload,
            );
            $this->fail('Checkout accepted a payment method disabled after the screen loaded.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                [__('pos.validation.payment_method_unavailable')],
                $exception->errors()['tenders'],
            );
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_checkout_rejects_multiple_tenders_even_when_methods_are_configured(): void
    {
        $card = PaymentMethod::factory()->create(['type' => 'card', 'requires_reference' => true, 'available_in_pos' => true]);
        $this->shift->update(['status' => 'closed', 'closed_at' => now()]);
        app(PosDeviceManagement::class)->configurePaymentMethod($this->actor, $this->shift->posDevice, (int) $card->id, null);
        $this->shift = app(PosShiftService::class)->open($this->actor, (int) $this->shift->pos_device_id,
            (int) $this->cash->id, '0', (string) Str::uuid7());
        $payload = $this->payload();
        $payload['tenders'] = [
            ['payment_method_id' => $this->method->id, 'amount' => '40'],
            ['payment_method_id' => $card->id, 'amount' => '60', 'external_reference' => 'CARD-123'],
        ];
        $payload['cash_received'] = '40';
        $before = $this->snapshot();

        try {
            app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), $payload);
            $this->fail('A split payment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tenders', $exception->errors());
            $this->assertSame(__('pos.validation.single_payment_method'), $exception->errors()['tenders'][0]);
        }

        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['underpaid'])]
    #[TestWith(['short_cash'])]
    #[TestWith(['closed_shift'])]
    #[TestWith(['insufficient_stock'])]
    public function test_invalid_checkout_preserves_all_documents_stock_and_finances(string $case): void
    {
        $payload = $this->payload();
        if ($case === 'underpaid') {
            $payload['tenders'][0]['amount'] = '99';
        } elseif ($case === 'short_cash') {
            $payload['cash_received'] = '99';
        } elseif ($case === 'closed_shift') {
            app(PosShiftService::class)->close($this->actor, (int) $this->shift->id,
                ['counted_cash' => '0', 'tender_counts' => []], (string) Str::uuid7());
        } else {
            $payload['items'][0]['quantity'] = '11';
        }
        $before = $this->snapshot();
        try {
            app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, (string) Str::uuid7(), $payload);
            $this->fail('Invalid checkout accepted.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_late_checkout_failure_rolls_back_and_same_key_can_retry(): void
    {
        $before = $this->snapshot();
        $shouldFail = true;
        Event::listen('eloquent.updated: '.PosTransaction::class, function (PosTransaction $transaction) use (&$shouldFail): void {
            if ($shouldFail && $transaction->sync_status === 'completed') {
                throw new \RuntimeException('Late checkout failure');
            }
        });
        $key = (string) Str::uuid7();
        try {
            app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id, $key, $this->payload());
            $this->fail('Expected late failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Late checkout failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $shouldFail = false;
        $this->assertSame('completed', app(PosCheckoutService::class)
            ->checkout($this->actor, (int) $this->shift->id, $key, $this->payload())->sync_status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_opening_payment_refreshes_prices_changed_after_adding_to_cart(): void
    {
        $terminal = Livewire::test(PosTerminal::class)->call('addProduct', (int) $this->variant->product_id);
        $this->variant->product->update(['sale_price' => '120']);
        $terminal->call('openCheckout')->assertHasNoErrors()
            ->assertSet('cartQuote.total_amount', '120.0000')
            ->assertSet('tenders.'.$this->method->id.'.amount', '120.0000')
            ->assertSet('cashReceived', '120.0000');
    }

    #[TestWith(['price', '120.0000'])]
    #[TestWith(['tax', '114.0000'])]
    public function test_changed_prices_or_taxes_require_review_and_checkout_can_retry(string $change, string $total): void
    {
        $terminal = Livewire::test(PosTerminal::class)->call('addProduct', (int) $this->variant->product_id)->call('openCheckout');
        $key = $terminal->get('clientTransactionId');
        if ($change === 'price') {
            $this->variant->product->update(['sale_price' => '120']);
        } else {
            $this->configureSalesPricing($this->variant, '0', '14');
        }
        $terminal->call('checkout')->assertHasErrors(['cart'])
            ->assertSet('cartQuote.total_amount', $total)->assertSet('showCheckout', false)->assertSet('tenders', []);
        $this->assertDatabaseCount('pos_transactions', 0);
        $terminal->call('openCheckout')->assertSet('tenders.'.$this->method->id.'.amount', $total)
            ->call('checkout')->assertHasNoErrors();
        $transaction = PosTransaction::firstOrFail();
        $this->assertSame($key, $transaction->client_transaction_id);
        $this->assertSame($total, $transaction->salesInvoice->total_amount);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_terminal_retries_committed_checkout_after_response_loss_even_when_stock_is_depleted(): void
    {
        $terminal = Livewire::test(PosTerminal::class)->set('cart', [
            $this->variant->id => ['quantity' => '10', 'discount_id' => null],
        ])->call('openCheckout');
        $payload = $this->payload();
        $payload['items'][0]['quantity'] = '10';
        $payload['tenders'][0]['amount'] = '1000';
        $payload['cash_received'] = '1000';
        $transaction = app(PosCheckoutService::class)->checkout($this->actor, (int) $this->shift->id,
            $terminal->get('clientTransactionId'), $payload);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant->id,
            'location_id' => $this->branch->id, 'quantity_available' => '0']);
        $terminal->call('checkout')->assertHasNoErrors()->assertSet('receiptUuid', $transaction->uuid);
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_customer_search_remains_available_when_empty_and_can_recover_and_settle_customer_invoice(): void
    {
        $customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'Known customer']);
        $terminal = Livewire::test(PosTerminal::class)->call('addProduct', (int) $this->variant->product_id)->call('openCheckout');
        $terminal->set('customerSearch', 'NO_MATCH_123');
        $html = implode('', $terminal->effects['islandFragments']);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="customerSearch"', $html);
        $this->assertStringContainsString('wire:model.live="customerId"', $html);
        $this->assertStringNotContainsString('Known customer', $html);
        $terminal->set('customerSearch', 'Known');
        $this->assertStringContainsString('Known customer', implode('', $terminal->effects['islandFragments']));
        $terminal->set('customerId', (int) $customer->id)->call('checkout')->assertHasNoErrors();
        $transaction = PosTransaction::firstOrFail();
        $this->assertDatabaseHas('customer_invoice_accounts', ['customer_id' => $customer->id, 'sales_invoice_id' => $transaction->sales_invoice_id]);
        $this->assertDatabaseHas('payments', ['pos_transaction_id' => $transaction->id, 'customer_id' => $customer->id]);
    }

    public function test_customer_controls_are_hidden_without_allocation_permission_and_checkout_cannot_bypass_it(): void
    {
        $this->actor->revokePermissionTo(PaymentsPermissions::ALLOCATE);
        Livewire::test(PosTerminal::class)->call('addProduct', (int) $this->variant->product_id)->call('openCheckout')
            ->assertDontSeeHtml('wire:model.live.debounce.300ms="customerSearch"')
            ->call('checkout')->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    /** @return array{TestResponse, list<array<string, mixed>>} */
    private function measureTerminalRequest(string $operation, Closure $request): array
    {
        app('livewire')->flushState();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        try {
            $response = $request()->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $patterns = [];
        foreach ($queries as $query) {
            $patterns[$query['query']] = ($patterns[$query['query']] ?? 0) + 1;
        }
        arsort($patterns);
        $metrics = ['phase' => getenv('POS_QUERY_PHASE') ?: 'current', 'operation' => $operation,
            'queries' => count($queries), 'database_ms' => round(array_sum(array_column($queries, 'time')), 2),
            'request_ms' => round((hrtime(true) - $start) / 1_000_000, 2)];
        $budgets = ['open' => 35, 'selectVariant' => 35, 'addProduct' => 32, 'changeQuantity' => 30, 'openCheckout' => 36,
            'setDiscount' => 30, 'removeFromCart' => 24, 'clearCart' => 24, 'addTender' => 35, 'removeTender' => 35,
            'paymentUpdate' => 35, 'closeCheckout' => 20, 'filter' => 35, 'page' => 35,
            'openReceipt' => 40, 'startNewSale' => 15, 'checkout' => 1000, 'printReceipt' => 40];
        fwrite(STDOUT, 'POS_QUERY '.json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL);
        if ($output = getenv('POS_QUERY_OUTPUT')) {
            $this->assertSame(realpath(storage_path('framework')), realpath(dirname($output)));
            file_put_contents($output, json_encode($metrics + ['patterns' => $patterns], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        $this->assertLessThanOrEqual($budgets[$operation], count($queries), 'POS request query costs must remain bounded.');
        $this->assertLessThanOrEqual(1, count(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'information_schema.tables') && str_contains($query['query'], 'modules_permissions'))));
        if ($operation !== 'checkout') {
            $this->assertSame([], array_values(array_filter($queries,
                fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1)),
                'Terminal display interactions must not write business data.');
        }

        return [$response, $queries];
    }

    private function terminalSnapshot(TestResponse $response): string
    {
        if (str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            return $response->json('components.0.snapshot');
        }
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            if (json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] === PosTerminal::class) {
                return $snapshot;
            }
        }
        $this->fail('The terminal must return its Livewire snapshot.');
    }

    /** @param array<array-key, mixed> $parameters */
    private function terminalCall(TestResponse $response, string $method, array $parameters = [], ?string $island = null): TestResponse
    {
        $updates = [];
        if ($method === '$set') {
            $updates[$parameters[0]] = $parameters[1];
            $method = '$refresh';
            $parameters = [];
        }

        return $this->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => $this->terminalSnapshot($response), 'updates' => $updates,
            'calls' => [['path' => '', 'method' => $method, 'params' => $parameters,
                'metadata' => $island === null ? [] : ['island' => ['name' => $island, 'mode' => 'morph']],
            ]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
    }

    public function test_terminal_buttons_and_payment_inputs_only_refresh_their_affected_regions(): void
    {
        $brand = Brand::factory()->create(['is_active' => true]);
        $size = Size::factory()->create(['is_active' => true]);
        $color = Color::factory()->create(['is_active' => true]);
        $this->variant->product->update(['brand_id' => $brand->id]);
        $this->variant->update(['size_id' => $size->id, 'color_id' => $color->id]);
        $this->configureSalesPricing($this->variant, '5', '14');
        $discountId = (string) $this->variant->product->discounts()->value('discounts.id');
        $customer = app(CustomerAccounts::class)->save($this->actor, ['location_id' => $this->branch->id, 'name' => 'POS control customer']);
        for ($index = 0; $index < 9; $index++) {
            $product = Product::factory()->create(['name' => 'POS control product '.$index, 'status' => 'active', 'is_active' => true]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
                'quantity_on_hand' => '5', 'quantity_reserved' => '0', 'quantity_available' => '5']);
        }
        $response = $this->get('/admin/pos-terminal')->assertOk();
        $productId = (int) $this->variant->product_id;
        $variantId = (int) $this->variant->id;
        foreach ([
            ['filter', '$set', ['categoryId', (int) $this->variant->product->category_id], 'catalog', 'catalog'],
            ['filter', '$set', ['categoryId', null], 'catalog', 'catalog'],
            ['filter', '$set', ['brandId', (int) $brand->id], 'catalog', 'catalog'],
            ['filter', '$set', ['brandId', null], 'catalog', 'catalog'],
            ['filter', '$set', ['sizeId', (int) $size->id], 'catalog', 'catalog'],
            ['filter', '$set', ['sizeId', null], 'catalog', 'catalog'],
            ['filter', '$set', ['colorId', (int) $color->id], 'catalog', 'catalog'],
            ['filter', '$set', ['colorId', null], 'catalog', 'catalog'],
            ['filter', '$set', ['search', 'NO_POS_PRODUCT_MATCH'], 'catalog', 'catalog'],
            ['filter', '$set', ['search', ''], 'catalog', 'catalog'],
            ['page', 'nextPage', [], 'catalog', 'catalog'],
            ['page', 'previousPage', [], 'catalog', 'catalog'],
            ['addProduct', 'addProduct', [$productId], null, 'cart'],
            ['changeQuantity', 'changeQuantity', [$variantId, 1], 'cart', 'cart'],
            ['changeQuantity', 'changeQuantity', [$variantId, -1], 'cart', 'cart'],
            ['setDiscount', 'setDiscount', [$variantId, ''], 'cart', 'cart'],
            ['setDiscount', 'setDiscount', [$variantId, $discountId], 'cart', 'cart'],
            ['removeFromCart', 'removeFromCart', [$variantId], 'cart', 'cart'],
            ['addProduct', 'addProduct', [$productId], null, 'cart'],
            ['clearCart', 'clearCart', [], 'cart', 'cart'],
            ['addProduct', 'addProduct', [$productId], null, 'cart'],
            ['openCheckout', 'openCheckout', [], null, 'payment'],
            ['removeTender', 'removeTender', [(int) $this->method->id], 'payment', 'payment'],
            ['addTender', 'addTender', [(int) $this->method->id], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['customerSearch', 'POS control'], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['customerName', 'Walk in name'], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['customerPhone', '01012345678'], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['customerId', (int) $customer->id], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['cashReceived', '120'], 'payment', 'payment'],
            ['paymentUpdate', '$set', ['tenders.'.$this->method->id.'.amount', '108.30'], 'payment', 'payment'],
            ['closeCheckout', '$set', ['showCheckout', false], 'payment', 'payment'],
        ] as [$operation, $method, $parameters, $island, $expectedRegion]) {
            [$response] = $this->measureTerminalRequest($operation,
                fn (): TestResponse => $this->terminalCall($response, $method, $parameters, $island));
            $this->assertNull($response->json('components.0.effects.html'));
            $fragments = implode('', $response->json('components.0.effects.islandFragments'));
            if ($method === '$set' && ! str_contains($parameters[0], '.')) {
                $state = json_decode($this->terminalSnapshot($response), true, flags: JSON_THROW_ON_ERROR)['data'];
                $this->assertSame($parameters[1], $state[$parameters[0]]);
            }
            $this->assertStringContainsString('|name='.$expectedRegion.'|', $fragments);
            $this->assertStringNotContainsString('fox-pos-recent', $fragments);
            if ($expectedRegion !== 'catalog') {
                $this->assertStringNotContainsString('fox-pos-catalog-toolbar', $fragments);
            }
        }
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_authenticated_terminal_requests_have_bounded_query_costs(): void
    {
        [$response] = $this->measureTerminalRequest('open', fn (): TestResponse => $this->get('/admin/pos-terminal'));
        foreach ([
            ['selectVariant', [(int) $this->variant->product_id, (int) $this->variant->id]],
            ['addProduct', [(int) $this->variant->product_id]],
            ['changeQuantity', [(int) $this->variant->id, 1]],
            ['openCheckout', []],
            ['checkout', []],
            ['printReceipt', []],
        ] as [$method, $parameters]) {
            $snapshot = $this->terminalSnapshot($response);
            [$response] = $this->measureTerminalRequest($method, fn (): TestResponse => $this->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $method === 'checkout' ? ['cashReceived' => '220'] : [],
                    'calls' => [['path' => '', 'method' => $method, 'params' => $parameters,
                        'metadata' => match ($method) {
                            'selectVariant' => ['island' => ['name' => 'catalog', 'mode' => 'morph']],
                            'changeQuantity' => ['island' => ['name' => 'cart', 'mode' => 'morph']],
                            default => [],
                        },
                    ]],
                ]],
            ], ['X-Livewire' => 'true']));
            if (in_array($method, ['selectVariant', 'addProduct', 'changeQuantity'], true)) {
                $this->assertNull($response->json('components.0.effects.html'));
                $fragments = implode('', $response->json('components.0.effects.islandFragments'));
                if ($method === 'selectVariant') {
                    $this->assertStringContainsString('fox-pos-product-card', $fragments);
                    $this->assertStringNotContainsString('fox-pos-cart-items', $fragments);
                } else {
                    $this->assertStringContainsString('fox-pos-cart-items', $fragments);
                    $this->assertStringNotContainsString('fox-pos-product-card', $fragments);
                    $this->assertStringNotContainsString('fox-pos-recent', $fragments);
                    $state = json_decode($this->terminalSnapshot($response), true, flags: JSON_THROW_ON_ERROR)['data'];
                    $this->assertSame($method === 'addProduct' ? '100.0000' : '200.0000', $state['cartQuote'][0]['total_amount']);
                }
            }
            if ($method === 'checkout') {
                $html = implode('', $response->json('components.0.effects.islandFragments'));
                $this->assertStringContainsString('x-on:click="window.print()"', $html);
                $this->assertStringNotContainsString('wire:click="printReceipt"', $html);
                $this->assertStringContainsString('fox-pos-recent', $html);
            }
        }
        $this->assertNull($response->json('components.0.effects.html'));
        $this->assertContains('pos-print-receipt', array_column($response->json('components.0.effects.dispatches'), 'name'));
        $transaction = PosTransaction::firstOrFail();
        [$response] = $this->measureTerminalRequest('openReceipt',
            fn (): TestResponse => $this->terminalCall($response, 'openReceipt', [$transaction->uuid]));
        $fragments = implode('', $response->json('components.0.effects.islandFragments'));
        $this->assertNull($response->json('components.0.effects.html'));
        $this->assertStringContainsString($transaction->salesInvoice->invoice_number, $fragments);
        $this->assertStringNotContainsString('fox-pos-catalog-toolbar', $fragments);
        [$response] = $this->measureTerminalRequest('startNewSale',
            fn (): TestResponse => $this->terminalCall($response, 'startNewSale', [], 'receipt'));
        $state = json_decode($this->terminalSnapshot($response), true, flags: JSON_THROW_ON_ERROR)['data'];
        $this->assertNull($state['receiptUuid']);
        $this->assertNull($response->json('components.0.effects.html'));
        $invoiceNumber = $transaction->salesInvoice->invoice_number;
        DB::transaction(fn () => RequestAuthorizationFacts::duringLockedTransaction(function () use ($transaction, $invoiceNumber): void {
            $invoice = SalesInvoice::query()->whereKey($transaction->sales_invoice_id)->firstOrFail();
            $this->assertSame($invoiceNumber, $invoice->display_reference);
        }));
        $this->assertDatabaseCount('pos_transactions', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '8.0000', 'quantity_reserved' => '0.0000']);
        app(LocationContext::class)->setFor($this->actor, (int) $this->branch->id);
        $this->assertSame('200.0000', app(TreasuryService::class)->balance($this->actor, $this->cash));
    }

    public function test_partial_cart_updates_refresh_pricing_and_show_stock_errors(): void
    {
        $this->configureSalesPricing($this->variant, '5', '14');
        $terminal = Livewire::test(PosTerminal::class)
            ->call('addProduct', (int) $this->variant->product_id)
            ->assertHasNoErrors()->assertSet('cartQuote.total_amount', '108.3000');

        $this->variant->product->update(['sale_price' => '120']);
        $terminal->call('changeQuantity', (int) $this->variant->id, 1)
            ->assertHasNoErrors()->assertSet('cartQuote.total_amount', '259.9200')
            ->assertSet('cartQuote.discount_amount', '12.0000')->assertSet('cartQuote.tax_amount', '31.9200');

        $terminal->call('setDiscount', (int) $this->variant->id, '')
            ->assertHasNoErrors()->assertSet('cartQuote.total_amount', '273.6000')
            ->assertSet('cart.'.$this->variant->id.'.discount_id', null);
        $discountId = (int) $this->variant->product->discounts()->value('discounts.id');
        $terminal->call('setDiscount', (int) $this->variant->id, (string) $discountId)
            ->assertHasNoErrors()->assertSet('cartQuote.total_amount', '259.9200')
            ->assertSet('cart.'.$this->variant->id.'.discount_id', $discountId);
        $terminal->call('setDiscount', (int) $this->variant->id, null)
            ->assertHasNoErrors()->assertSet('cartQuote.total_amount', '273.6000');
        StockBalance::query()->where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)
            ->update(['quantity_on_hand' => '2', 'quantity_available' => '2']);
        $terminal->call('changeQuantity', (int) $this->variant->id, 1)
            ->assertHasErrors(['cart.'.$this->variant->id.'.quantity']);
        $fragments = implode('', $terminal->effects['islandFragments']);
        $this->assertStringContainsString(e(__('pos.validation.variant_unavailable')), $fragments);
        $this->assertStringNotContainsString('fox-pos-product-card', $fragments);
        $terminal->call('removeFromCart', (int) $this->variant->id)
            ->assertSet('cart', [])->assertSet('cartQuote.total_amount', '0.0000');
        $this->assertDatabaseCount('pos_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cart_discount_rejects_invalid_strings_and_unavailable_discounts(): void
    {
        $terminal = Livewire::test(PosTerminal::class)->call('addProduct', (int) $this->variant->product_id);
        $terminal->call('setDiscount', (int) $this->variant->id, 'invalid')
            ->assertHasErrors(['discount_id'])->assertSet('cartQuote.total_amount', '100.0000')
            ->assertSet('cart.'.$this->variant->id.'.discount_id', null);
        $terminal->call('setDiscount', (int) $this->variant->id, '999999999')
            ->assertForbidden();
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_adding_a_product_uses_the_variant_matching_catalog_size_filters(): void
    {
        $size = Size::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'size_id' => $size->id]);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->branch->id,
            'quantity_on_hand' => '2', 'quantity_reserved' => '0', 'quantity_available' => '2']);

        Livewire::test(PosTerminal::class)->set('sizeId', (int) $size->id)
            ->call('addProduct', (int) $variant->product_id)->assertHasNoErrors()
            ->assertSet('cart', [$variant->id => ['quantity' => 1, 'discount_id' => null]])
            ->assertSet('cartQuote.total_amount', '100.0000');
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    #[TestWith(['inactive-product'])]
    #[TestWith(['inactive-variant'])]
    #[TestWith(['other-branch'])]
    #[TestWith(['wrong-product'])]
    public function test_adding_cart_products_still_rejects_unavailable_variants(string $case): void
    {
        $terminal = Livewire::test(PosTerminal::class);
        match ($case) {
            'inactive-product' => $this->variant->product->update(['is_active' => false]),
            'inactive-variant' => $this->variant->update(['is_active' => false]),
            'other-branch' => StockBalance::query()->where('variant_id', $this->variant->id)
                ->update(['location_id' => Location::create(['name' => 'Other stock branch', 'type' => 'branch', 'is_active' => true])->id]),
            'wrong-product' => $terminal->set('selectedVariantIds.'.$this->variant->product_id,
                (string) ProductVariant::factory()->create()->id),
        };

        $terminal->call('addProduct', (int) $this->variant->product_id)
            ->assertHasErrors(['cart'])->assertSet('cart', []);
        $this->assertDatabaseCount('pos_transactions', 0);
    }

    public function test_pos_request_facts_reauthorize_after_branch_deactivation_and_permission_unmapping(): void
    {
        $request = request();
        RequestAuthorizationFacts::begin($request);
        try {
            $access = app(PosAccess::class);
            $this->assertTrue($access->can($this->actor, PosPermissions::CHECKOUT));
            $access->authorize($this->actor, PosPermissions::CHECKOUT, (int) $this->branch->id);
            DB::table('locations')->where('id', $this->branch->id)->update(['is_active' => false]);
            try {
                $access->authorize($this->actor, PosPermissions::CHECKOUT, (int) $this->branch->id);
                $this->fail('An inactive POS branch must be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            DB::table('locations')->where('id', $this->branch->id)->update(['is_active' => true]);
            $access->authorize($this->actor, PosPermissions::CHECKOUT, (int) $this->branch->id);
            $module = Module::where('code', 'pos')->firstOrFail();
            $module->permissions()->where('name', PosPermissions::CHECKOUT)->get()->each(fn ($permission) => $module->permissions()->detach($permission));
            $this->assertFalse($access->can($this->actor, PosPermissions::CHECKOUT));
        } finally {
            RequestAuthorizationFacts::end($request);
        }
    }

    public function test_terminal_checkout_renders_receipt_and_starts_another_sale(): void
    {
        $terminal = Livewire::test(PosTerminal::class)->assertOk()
            ->call('addProduct', (int) $this->variant->product_id)
            ->call('openCheckout')->assertSet('showCheckout', true)
            ->set('cashReceived', '120')->call('checkout')->assertHasNoErrors()
            ->assertSet('showCheckout', false)->assertSet('cart', []);
        $transaction = PosTransaction::firstOrFail();
        $key = $terminal->get('clientTransactionId');
        $terminal->assertSet('receiptUuid', $transaction->uuid);
        $this->assertStringContainsString($transaction->salesInvoice->invoice_number, implode('', $terminal->effects['islandFragments']));
        $terminal->call('printReceipt')->assertDispatched('pos-print-receipt')
            ->call('startNewSale')->assertSet('receiptUuid', null);
        $this->assertNotSame($key, $terminal->get('clientTransactionId'));
        $terminal->call('addProduct', (int) $this->variant->product_id)
            ->call('openCheckout')->call('checkout')->assertHasNoErrors();
        $this->assertDatabaseCount('pos_transactions', 2);
        $this->assertDatabaseCount('payments', 2);
    }
}
