<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\ProfitabilityReport;
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
use App\Modules\Treasury\Services\TreasuryPermissions;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PhaseZeroSafetyVerificationTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    public function createApplication(): Application
    {
        $database = getenv('PHASE0_DATABASE');
        if (! is_string($database) || ! preg_match('/^codex_public_ids_test_phase0_[a-f0-9]{12}$/D', $database)
            || getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== $database
            || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '33316'
            || ! in_array(getenv('DB_URL'), ['', false], true) || ! in_array(getenv('DB_SOCKET'), ['', false], true)
            || ! getenv('APP_CONFIG_CACHE') || is_file(getenv('APP_CONFIG_CACHE'))) {
            throw new LogicException('Phase 0 requires the explicitly provisioned disposable loopback schema.');
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-phase0-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make('db')->connection();
        if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
            || $connection->getConfig('host') !== '127.0.0.1' || (string) $connection->getConfig('port') !== '33316'
            || $connection->getDatabaseName() !== $database
            || $connection->selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new LogicException('Resolved Phase 0 connection differs from its disposable identity.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::query()->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->branch = Location::create(['name' => 'Phase 0 branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = $this->operator($this->branch);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->configureSalesPricing($this->variant, '0', '0');
        $this->actingAs($this->actor);
    }

    private function operator(Location $branch): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $permissions = [...InventoryPermissions::all(), ...PurchasingPermissions::all(), ...SalesPermissions::all(),
            ...PaymentsPermissions::all(), ...TreasuryPermissions::all(), ...ProductPermissions::all()];
        $user->givePermissionTo(array_values(array_filter($permissions, fn (string $permission): bool => ! str_contains($permission, 'all_locations'))));

        return $user;
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidBalances(): array
    {
        return ['negative on hand' => ['-1', '0', '-1'], 'negative reserved' => ['1', '-1', '2'],
            'reserved exceeds on hand' => ['1', '2', '-1'], 'available mismatch' => ['1', '0', '99']];
    }

    #[DataProvider('invalidBalances')]
    public function test_f01_direct_database_inserts_and_updates_reject_invalid_stock_arithmetic(string $onHand, string $reserved, string $available): void
    {
        $checks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balances' AND CONSTRAINT_TYPE = 'CHECK'");
        $this->assertCount(4, $checks);
        $bad = ['uuid' => (string) Str::uuid7(), 'variant_id' => $this->variant->id,
            'location_id' => $this->branch->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved, 'quantity_available' => $available,
            'created_at' => now(), 'updated_at' => now()];
        try {
            DB::table('stock_balances')->insert($bad);
            $this->fail('Invalid direct INSERT must be rejected.');
        } catch (QueryException $exception) {
            $this->assertContains((int) $exception->errorInfo[1], [3819, 4025]);
        }
        $this->assertDatabaseCount('stock_balances', 0);
        $balance = StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->branch->id]);
        $before = $balance->fresh()->getAttributes();
        try {
            DB::table('stock_balances')->where('id', $balance->id)->update($bad);
            $this->fail('Invalid direct UPDATE must be rejected.');
        } catch (QueryException $exception) {
            $this->assertContains((int) $exception->errorInfo[1], [3819, 4025]);
        }
        $this->assertSame($before, $balance->fresh()->getAttributes());
    }

    public function test_f01_legitimate_posting_preserves_available_and_reserved_stock(): void
    {
        $service = app(InventoryPostingService::class);
        $service->post(new StockPostingCommand($this->variant, $this->branch, StockMovementType::Purchase, '5', (string) Str::uuid7()), $this->actor);
        $balance = StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
        $balance->update(['quantity_reserved' => '1', 'quantity_available' => '4']);
        $service->post(new StockPostingCommand($this->variant, $this->branch, StockMovementType::Sale, '-4', (string) Str::uuid7()), $this->actor);
        $balance->refresh();
        $this->assertSame('1.0000', $balance->quantity_on_hand);
        $this->assertSame('1.0000', $balance->quantity_reserved);
        $this->assertSame('0.0000', $balance->quantity_available);
        $this->expectException(ValidationException::class);
        $service->post(new StockPostingCommand($this->variant, $this->branch, StockMovementType::Sale, '-0.0001', (string) Str::uuid7()), $this->actor);
    }

    /** @return array{Customer, Payment} */
    private function receiptPayment(User $actor, Location $branch, bool $post = true): array
    {
        setPermissionsTeamId($branch->id);
        $customer = app(CustomerAccounts::class)->save($actor, ['location_id' => $branch->id, 'name' => 'Phase 0 customer']);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        $account = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $service = app(CustomerPayments::class);
        $payment = $service->createDraft($actor, ['customer_id' => $customer->id,
            'payment_method_id' => $method->id, 'treasury_account_id' => $account->id, 'amount' => '100',
            'payment_date' => today()->toDateString()], (string) Str::uuid7());
        if ($post) {
            $payment = $service->post($actor, $payment);
        }

        return [$customer, $payment];
    }

    public function test_r01_modified_payment_headers_do_not_influence_direct_service_reads(): void
    {
        [, $payment] = $this->receiptPayment($this->actor, $this->branch);
        $service = app(CustomerFinancials::class);
        $this->assertSame('100.0000', $service->available($payment->fresh(), $this->actor));
        $payment->amount = '999';
        $this->assertSame('100.0000', $service->available($payment, $this->actor));
        $payment->status = 'draft';
        $this->assertSame('100.0000', $service->available($payment, $this->actor));
        $payment->setConnection('untrusted_supplied_connection');
        $this->assertSame('100.0000', $service->available($payment, $this->actor, locking: true));
        $payment->setConnection(null);
        $this->assertSame('100.0000', $payment->fresh()->amount);
    }

    public function test_r01_stale_draft_snapshot_uses_authoritatively_posted_payment(): void
    {
        [, $draft] = $this->receiptPayment($this->actor, $this->branch, post: false);
        app(CustomerPayments::class)->post($this->actor, $draft);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('100.0000', app(CustomerFinancials::class)->available($draft, $this->actor));
        $this->assertSame('100.0000', app(CustomerFinancials::class)->available($draft->fresh(), $this->actor));
    }

    public function test_r01_forged_payment_location_is_denied_at_service_and_http_boundaries(): void
    {
        $foreign = Location::create(['name' => 'Foreign payment branch', 'type' => 'branch', 'is_active' => true]);
        $foreignActor = $this->operator($foreign);
        [, $payment] = $this->receiptPayment($foreignActor, $foreign);
        setPermissionsTeamId($this->branch->id);
        $this->actingAs($this->actor);
        try {
            app(CustomerFinancials::class)->available($payment, $this->actor);
            $this->fail('Unmodified foreign payment must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $payment->location_id = $this->branch->id;
        $payment->customer_id = null;
        $payment->setConnection('untrusted_supplied_connection');
        foreach ([false, true] as $locking) {
            try {
                app(CustomerFinancials::class)->available($payment, $this->actor, $locking);
                $this->fail('Forged foreign payment must be denied.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertNull(CustomerPaymentResource::getEloquentQuery()->find($payment->id));
        Livewire::test(ViewCustomerPayment::class, ['record' => $payment->uuid])->assertNotFound();
        $this->get(CustomerPaymentResource::getUrl('view', ['record' => $payment->uuid]))->assertNotFound();
    }

    public function test_r01_customer_aggregate_rechecks_persisted_payment_locations(): void
    {
        $foreign = Location::create(['name' => 'Foreign customer branch', 'type' => 'branch', 'is_active' => true]);
        $foreignActor = $this->operator($foreign);
        [$customer] = $this->receiptPayment($foreignActor, $foreign);
        setPermissionsTeamId($this->branch->id);
        $customer->location_id = $this->branch->id;
        $this->expectException(HttpException::class);
        app(CustomerFinancials::class)->customer($this->actor, $customer);
    }

    public function test_r01_forged_posted_state_cannot_make_a_persisted_draft_available(): void
    {
        [, $payment] = $this->receiptPayment($this->actor, $this->branch, post: false);
        $before = $this->financialEvidence();
        $payment->status = 'posted';
        $payment->amount = '999';
        foreach ([false, true] as $locking) {
            $this->assertSame('0.0000', app(CustomerFinancials::class)->available($payment, $this->actor, $locking));
        }
        $this->assertSame($before, $this->financialEvidence());
    }

    public function test_r01_foreign_customer_without_payments_cannot_forge_ownership(): void
    {
        $foreign = Location::create(['name' => 'Empty foreign customer branch', 'type' => 'branch', 'is_active' => true]);
        $foreignActor = $this->operator($foreign);
        $customer = app(CustomerAccounts::class)->save($foreignActor, ['location_id' => $foreign->id, 'name' => 'Foreign customer']);
        setPermissionsTeamId($this->branch->id);
        $customer->location_id = $this->branch->id;
        $customer->setConnection('untrusted_supplied_connection');
        $this->expectException(HttpException::class);
        app(CustomerFinancials::class)->customer($this->actor, $customer);
    }

    public function test_r01_customer_headers_are_reloaded_and_financial_reads_do_not_write(): void
    {
        [$customer, $payment] = $this->receiptPayment($this->actor, $this->branch);
        $before = $this->financialEvidence();
        $customer->location_id = 0;
        $customer->setConnection('untrusted_supplied_connection');
        $this->assertSame(['outstanding' => '0.0000', 'advance' => '100.0000', 'net_balance' => '-100.0000'],
            app(CustomerFinancials::class)->customer($this->actor, $customer));
        $this->assertSame('100.0000', app(CustomerFinancials::class)->available($payment, $this->actor));
        $this->assertSame($before, $this->financialEvidence());
    }

    public function test_r01_missing_payment_and_customer_fail_closed(): void
    {
        foreach ([(new Payment)->forceFill(['id' => PHP_INT_MAX]), (new Customer)->forceFill(['id' => PHP_INT_MAX])] as $record) {
            try {
                if ($record instanceof Payment) {
                    app(CustomerFinancials::class)->available($record, $this->actor);
                } else {
                    app(CustomerFinancials::class)->customer($this->actor, $record);
                }
                $this->fail('Missing financial header must be rejected.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame($record::class, $exception->getModel());
            }
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function financialEvidence(): array
    {
        return $this->tableEvidence(['payment_customers', 'payments', 'payment_allocations', 'payment_refunds',
            'treasury_operations', 'document_numbers', 'document_number_sequences']);
    }

    /** @param array<int, string> $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function tableEvidence(array $tables): array
    {
        $evidence = [];
        foreach ($tables as $table) {
            $evidence[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $evidence;
    }

    private function purchaseReceipt(User $actor, Location $branch, bool $post = true): PurchaseReceipt
    {
        setPermissionsTeamId($branch->id);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $branch->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $this->variant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                'variant_id' => $this->variant->id, 'quantity' => '6', 'unit_price' => '3']]], (string) Str::uuid7());
        $order = $orders->confirm($actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $service = app(PurchaseReceiptService::class);
        $receipt = $service->update($actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => '6', 'unit_cost' => '3']]], $service->revision($receipt));

        return $post ? $service->post($actor, $receipt, $service->revision($receipt)) : $receipt;
    }

    /** @return array<string, array{string}> */
    public static function receiptMismatches(): array
    {
        return ['quantity' => ['quantity'], 'location' => ['location'], 'variant' => ['variant'],
            'unit cost' => ['cost'], 'receipt ownership' => ['ownership'], 'new operation identity' => ['identity'],
            'exact draft command' => ['draft'], 'unvalued draft command' => ['unvalued'], 'recorded command replay' => ['replay']];
    }

    #[DataProvider('receiptMismatches')]
    public function test_r02_direct_receipt_commands_cannot_bypass_receipt_orchestration(string $mismatch): void
    {
        $receipt = $this->purchaseReceipt($this->actor, $this->branch, post: ! in_array($mismatch, ['draft', 'unvalued'], true));
        $item = $receipt->items()->firstOrFail();
        $location = $this->branch;
        $actor = $this->actor;
        $variant = $this->variant;
        if ($mismatch === 'location') {
            $location = Location::create(['name' => 'Other command branch', 'type' => 'branch', 'is_active' => true]);
            $actor = $this->operator($location);
        } elseif ($mismatch === 'ownership') {
            $foreign = Location::create(['name' => 'Other receipt owner', 'type' => 'branch', 'is_active' => true]);
            $foreignActor = $this->operator($foreign);
            $item = $this->purchaseReceipt($foreignActor, $foreign)->items()->firstOrFail();
            setPermissionsTeamId($this->branch->id);
        } elseif ($mismatch === 'variant') {
            $variant = ProductVariant::factory()->create();
        }
        $exact = in_array($mismatch, ['draft', 'unvalued', 'replay'], true);
        $quantity = $mismatch === 'quantity' ? '7' : ($exact ? '6.0000' : '1');
        $command = new StockPostingCommand($variant, $location, StockMovementType::Purchase, $quantity,
            $exact ? 'purchase-receipt-item:'.$item->uuid.':validate' : 'phase0-forged:'.Str::uuid7(), reference: $item,
            valuationUnitCost: $mismatch === 'unvalued' ? null : ($mismatch === 'cost' ? '999.123456' : '3.000000'));
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers',
            'fifo_layer_consumptions', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items',
            'document_numbers', 'document_number_sequences'];
        $before = $this->tableEvidence($tables);
        try {
            DB::transaction(fn (): StockMovement => app(InventoryPostingService::class)->post($command, $actor));
            $this->fail('A caller-created receipt command must never be authorized.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valuation', $exception->errors());
        }
        $this->assertFalse(app(PurchaseReceiptService::class)->authorizesCommand($command, $actor));
        $this->assertSame($before, $this->tableEvidence($tables));
    }

    public function test_r02_wrong_source_document_type_is_rejected(): void
    {
        $receipt = $this->purchaseReceipt($this->actor, $this->branch, post: false);
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(InventoryPostingService::class)->post(new StockPostingCommand(
            $this->variant, $this->branch, StockMovementType::Purchase, '1', (string) Str::uuid7(),
            reference: $receipt, valuationUnitCost: '3.000000'), $this->actor));
    }

    public function test_r02_stock_handoff_rejects_copied_commands_and_clears_authority_after_rollback(): void
    {
        $receipt = $this->purchaseReceipt($this->actor, $this->branch, post: false);
        $otherActor = $this->operator($this->branch);
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_states', 'inventory_cost_entries',
            'fifo_layers', 'purchase_receipts', 'purchase_receipt_items', 'document_numbers', 'document_number_sequences'];
        $before = $this->tableEvidence($tables);
        $captured = null;
        $inventory = \Mockery::mock(InventoryPostingService::class);
        $inventory->shouldReceive('postMany')->once()->andReturnUsing(function (array $commands, User $actor) use (&$captured, $otherActor): array {
            $captured = $commands[0];
            $service = app(PurchaseReceiptService::class);
            $this->assertTrue($service->authorizesCommand($captured, $actor));
            $this->assertFalse($service->authorizesCommand($captured, $otherActor));
            $this->assertFalse(DB::transaction(fn (): bool => $service->authorizesCommand($captured, $actor)));
            $original = $captured->reference->quantity;
            $captured->reference->quantity = '999.0000';
            $this->assertFalse($service->authorizesCommand($captured, $actor));
            $captured->reference->quantity = $original;
            try {
                (new InventoryPostingService)->post(clone $captured, $actor);
                $this->fail('Copying a trusted command must not copy its authority.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('valuation', $exception->errors());
            }
            (new InventoryPostingService)->postMany($commands, $actor);
            DB::rollBack();
            DB::beginTransaction();
            $this->assertFalse($service->authorizesCommand($captured, $actor));
            throw new \RuntimeException('Injected failure after stock handoff.');
        });
        $this->app->instance(InventoryPostingService::class, $inventory);
        $this->app->forgetInstance(PurchaseReceiptService::class);
        $service = app(PurchaseReceiptService::class);
        try {
            $service->post($this->actor, $receipt, $service->revision($receipt));
            $this->fail('Injected stock handoff failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure after stock handoff.', $exception->getMessage());
        }
        $this->assertInstanceOf(StockPostingCommand::class, $captured);
        $this->assertFalse($service->authorizesCommand($captured, $this->actor));
        $this->assertSame($before, $this->tableEvidence($tables));
        try {
            DB::transaction(fn () => (new InventoryPostingService)->post($captured, $this->actor));
            $this->fail('Failed handoff authority must not survive rollback.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valuation', $exception->errors());
        }
        $this->app->forgetInstance(InventoryPostingService::class);
        $this->app->forgetInstance(PurchaseReceiptService::class);
        $service = app(PurchaseReceiptService::class);
        $posted = $service->post($this->actor, $receipt, $service->revision($receipt));
        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->items->first()->stockMovement->inventoryCostEntry);
        $this->assertSame($posted->id, $service->post($this->actor, $posted, $service->revision($posted))->id);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('inventory_cost_entries', 1);
    }

    private function delivered(int $lines = 1): SalesDelivery
    {
        $this->purchaseReceipt($this->actor, $this->branch);
        $orders = app(SalesOrderService::class);
        $order = $orders->create($this->actor, ['location_id' => $this->branch->id, 'items' => array_fill(0, $lines, [
            'product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
            'quantity' => '3', 'unit_price' => '0.000033'])], (string) Str::uuid7());
        $order = app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
        $deliveries = app(SalesDeliveryService::class);
        $delivery = $deliveries->create($this->actor, ['sales_order_id' => $order->id, 'items' => $order->items()->orderBy('id')->get()
            ->map(fn ($item): array => ['sales_order_item_id' => $item->id, 'quantity' => '3'])->all()], (string) Str::uuid7());
        $delivery = app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $deliveries->revision($delivery));

        return $delivery;
    }

    private function returned(SalesDelivery $delivery): SalesReturn
    {
        return app(SalesReturnService::class)->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => today()->toDateString(),
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => '3', 'disposition' => 'restock'])->all()], (string) Str::uuid7());
    }

    private function invoice(SalesDelivery $delivery, string $quantity): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);
        $invoice = $service->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => today()->toDateString(),
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity])->all()], (string) Str::uuid7());

        return $service->post($this->actor, $invoice, $service->revision($invoice));
    }

    private function postReturn(SalesReturn $return): SalesReturn
    {
        $approver = $this->operator($this->branch);
        $returns = app(SalesReturnService::class);
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $returns->revision($return));

        return app(SalesReturnPostingService::class)->post($this->actor, $return, $returns->revision($return));
    }

    private function credit(SalesReturn $return, SalesInvoice $invoice, string $quantity): SalesCreditNote
    {
        $service = app(SalesCreditNoteService::class);
        $note = $service->create($this->actor, ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id,
            'credit_date' => today()->toDateString(), 'items' => $return->postedAllocations()->where('sales_invoice_id', $invoice->id)
                ->orderBy('id')->get()->map(fn ($row): array => ['sales_return_posted_allocation_id' => $row->id, 'quantity' => $quantity])->all()], (string) Str::uuid7());

        return $service->post($this->actor, $note, $service->revision($note));
    }

    private function assertFullyReturnedReportHasNoRevenueOrCost(): void
    {
        $service = app(ProfitabilityReport::class);
        $summary = $service->summary($service->events($this->actor, today()->toDateString(), today()->toDateString()));
        $this->assertSame('0.0000', $summary['revenue']);
        $this->assertSame('0.0000', $summary['cost']);
        $this->assertSame(0, $summary['missing']);
    }

    public function test_r06_one_return_item_across_invoice_matches_and_subsequent_fractional_credits(): void
    {
        $delivery = $this->delivered();
        $first = $this->invoice($delivery, '1');
        $second = $this->invoice($delivery, '2');
        $return = $this->postReturn($this->returned($delivery));
        $this->assertSame(1, $return->items()->count());
        $this->assertSame(2, $return->postedAllocations()->count());
        $this->credit($return, $first, '1');
        $this->assertFullyReturnedReportHasNoRevenueOrCost();
        $this->credit($return, $second, '1');
        $this->assertFullyReturnedReportHasNoRevenueOrCost();
        $this->credit($return, $second, '1');
        $this->assertFullyReturnedReportHasNoRevenueOrCost();
        $this->assertTrue(BigDecimal::of(SalesCreditNote::sum('total_amount'))->isEqualTo(BigDecimal::of($first->total_amount)->plus($second->total_amount)));
    }

    public function test_r06_multiple_same_note_slices_use_distinct_return_items_and_conserve_final_residuals(): void
    {
        $delivery = $this->delivered(2);
        $invoice = $this->invoice($delivery, '3');
        $return = $this->postReturn($this->returned($delivery));
        foreach (['1', '1', '1'] as $quantity) {
            $note = $this->credit($return, $invoice, $quantity);
            $this->assertSame(2, $note->items()->count());
            $this->assertSame(2, $note->items()->distinct()->count('sales_return_item_id'));
            $this->assertFullyReturnedReportHasNoRevenueOrCost();
        }
        $this->assertTrue(BigDecimal::of(SalesCreditNote::sum('total_amount'))->isEqualTo($invoice->total_amount));
    }
}
