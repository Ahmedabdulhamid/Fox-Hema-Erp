<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\FifoLayerValue;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\UnallocatedVariantService;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\DocumentTestSetup;
use Tests\TestCase;

class LockOrderConcurrencyTest extends TestCase
{
    use DocumentTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDocuments();
        $this->documentVariant->update(['size_id' => Size::factory()->create()->id]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->configurationIsCached());
        $server = DB::selectOne('SELECT DATABASE() AS db, @@datadir AS directory, @@port AS port');
        $this->assertSame(DB::connection()->getDatabaseName(), $server->db);
        $this->assertStringStartsWith('codex_public_ids_test_purchasing_', $server->db);
        $this->assertSame('127.0.0.1', config('database.connections.mysql.host'));
        $this->assertSame(getenv('LOCK_ORDER_EXPECTED_PORT'), (string) $server->port);
        $this->assertSame(rtrim(str_replace('\\', '/', getenv('LOCK_ORDER_EXPECTED_DATADIR')), '/'), rtrim(str_replace('\\', '/', $server->directory), '/'));
    }

    private function receipt(string $quantity, ?ProductVariant $variant = null, ?Location $branch = null, bool $post = true): PurchaseReceipt
    {
        $variant ??= $this->documentVariant;
        $branch ??= $this->documentBranch;
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->documentActor, [
            'supplier_id' => Supplier::factory()->create()->id, 'location_id' => $branch->id, 'order_date' => today()->toDateString(),
            'items' => [['product_id' => $variant->product_id, 'variant_id' => $variant->id,
                'receiving_mode' => $variant->isUnallocated() ? 'unknown' : 'known', 'quantity' => $quantity, 'unit_price' => '2']],
        ], (string) Str::uuid7());
        $order = $orders->confirm($this->documentActor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->documentActor, $receipt, ['items' => [[
            'id' => $receipt->items()->firstOrFail()->id, 'quantity' => $quantity, 'unit_cost' => '2',
        ]]], $receipts->revision($receipt));

        return $post ? $receipts->post($this->documentActor, $receipt, $receipts->revision($receipt)) : $receipt;
    }

    private function sale(bool $confirm = false): SalesOrder
    {
        $order = app(SalesOrderService::class)->create($this->documentActor, [
            'location_id' => $this->documentBranch->id, 'items' => [[
                'product_id' => $this->documentVariant->product_id, 'variant_id' => $this->documentVariant->id,
                'quantity' => '3', 'unit_price' => '10', 'discount_amount' => '0', 'tax_amount' => '0',
            ]],
        ], (string) Str::uuid7());

        return $confirm ? app(SalesOrderConfirmationService::class)->confirm($this->documentActor, $order) : $order;
    }

    private function delivery(): SalesDelivery
    {
        $order = $this->sale(true);

        return app(SalesDeliveryService::class)->create($this->documentActor, [
            'sales_order_id' => $order->id, 'items' => [['sales_order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => '3']],
        ], (string) Str::uuid7());
    }

    private function operation(string $type, PurchaseReceipt|SalesOrder|SalesDelivery|SizeAllocation $document): array
    {
        $service = match ($type) {
            'receipt' => app(PurchaseReceiptService::class),
            'confirm' => app(SalesOrderService::class),
            'delivery' => app(SalesDeliveryService::class),
            'allocation' => app(SizeAllocationService::class),
        };

        return ['type' => $type, 'id' => $document->id, 'revision' => $service->revision($document), 'actor' => $this->documentActor->id];
    }

    private function allocation(ProductVariant $source): SizeAllocation
    {
        return app(SizeAllocationService::class)->create($this->documentActor, [
            'product_id' => $source->product_id, 'location_id' => $this->documentBranch->id,
            'items' => [['variant_id' => $this->documentVariant->id, 'quantity' => '2']],
        ], (string) Str::uuid7());
    }

    private function standard(ProductVariant $variant): InventoryStandardCostRevision
    {
        $standard = InventoryStandardCost::factory()->create(['variant_id' => $variant->id, 'standard_cost' => '2']);

        return InventoryStandardCostRevision::factory()->create([
            'standard_cost_id' => $standard->id, 'new_cost' => '2', 'effective_at' => now()->subDay(),
        ]);
    }

    /** @return array{0: array, 1: array} */
    private function pair(array $first, array $second): array
    {
        $directory = sys_get_temp_dir().'/fox_hema_lock_order_'.bin2hex(random_bytes(8));
        mkdir($directory);
        $config = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_SOCKET' => '',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => DB::connection()->getDatabaseName(),
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'APP_CONFIG_CACHE' => getenv('APP_CONFIG_CACHE'), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'LOCK_ORDER_EXPECTED_DATADIR' => getenv('LOCK_ORDER_EXPECTED_DATADIR'), 'LOCK_ORDER_EXPECTED_PORT' => getenv('LOCK_ORDER_EXPECTED_PORT'),
            'LOCK_ORDER_BARRIER' => $directory,
        ];
        $processes = [];
        $evidence = ['test' => $this->name(), 'operations' => [$first, $second]];
        try {
            foreach (['a' => $first, 'b' => $second] as $name => $operation) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/LockOrderWorker.php')], base_path(), $environment + [
                    'LOCK_ORDER_WORKER' => $name, 'LOCK_ORDER_OPERATION' => json_encode($operation, JSON_THROW_ON_ERROR),
                ]);
                $process->setTimeout(45)->start();
                $processes[] = $process;
                $this->await(fn (): bool => is_file($directory.'/'.$name.'-ready.json'), 'Worker did not become ready.');
                if ($name === 'a') {
                    $this->await(fn (): bool => is_file($directory.'/a-held.json'), 'First worker did not reach its lock barrier.');
                }
            }
            $a = json_decode(file_get_contents($directory.'/a-ready.json'), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
            $b = json_decode(file_get_contents($directory.'/b-ready.json'), true, flags: JSON_THROW_ON_ERROR)['connection_id'];
            $evidence['held'] = json_decode(file_get_contents($directory.'/a-held.json'), true, flags: JSON_THROW_ON_ERROR);
            $evidence['connections'] = [$a, $b];
            $wait = null;
            $this->await(function () use ($a, $b, &$wait, &$evidence): bool {
                $wait = DB::selectOne('SELECT r.trx_mysql_thread_id AS waiting_connection, b.trx_mysql_thread_id AS blocking_connection,
                    r.trx_query AS waiting_query, l.lock_table, l.lock_index FROM information_schema.INNODB_LOCK_WAITS w
                    JOIN information_schema.INNODB_TRX r ON r.trx_id = w.requesting_trx_id
                    JOIN information_schema.INNODB_TRX b ON b.trx_id = w.blocking_trx_id
                    JOIN information_schema.INNODB_LOCKS l ON l.lock_id = w.requested_lock_id
                    WHERE r.trx_mysql_thread_id = ? AND b.trx_mysql_thread_id = ?', [$b, $a]);

                return $wait !== null;
            }, 'Independent worker did not enter the expected InnoDB lock wait.');
            $evidence['held'] = json_decode(file_get_contents($directory.'/a-held.json'), true, flags: JSON_THROW_ON_ERROR);
            $evidence['wait'] = $wait;
            touch($directory.'/release-a');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Lock-order worker exited unsuccessfully.');
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            $evidence['results'] = $results;

            return $results;
        } finally {
            touch($directory.'/release-a');
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            $evidence['worker_output'] = array_map(fn (Process $process): string => $process->getOutput(), $processes);
            $evidence['worker_errors'] = array_map(fn (Process $process): string => $process->getErrorOutput(), $processes);
            $artifacts = getenv('LOCK_ORDER_ARTIFACTS');
            if (is_string($artifacts) && is_dir($artifacts)) {
                file_put_contents($artifacts.'/'.basename($directory).'.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /** MariaDB's lock-information snapshot needs more than 100 ms idle between reads to refresh. */
    private function await(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 12;
        do {
            if ($condition()) {
                $this->assertTrue(true);

                return;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }

    private function assertCompleted(array $results): void
    {
        $this->assertSame(['completed', 'completed'], array_column($results, 'status'), json_encode($results, JSON_THROW_ON_ERROR));
        foreach ($results as $result) {
            $this->assertSame('registry', $result['locks'][0]);
        }
    }

    private function assertBalance(ProductVariant $variant, Location $branch, string $quantity, string $reserved = '0'): void
    {
        $balance = StockBalance::where('variant_id', $variant->id)->where('location_id', $branch->id)->firstOrFail();
        $this->assertSame((string) BigDecimal::of($quantity)->toScale(4), $balance->quantity_on_hand);
        $this->assertSame((string) BigDecimal::of($reserved)->toScale(4), $balance->quantity_reserved);
        $this->assertSame((string) BigDecimal::of($quantity)->minus($reserved)->toScale(4), $balance->quantity_available);
    }

    public function test_inventory_posting_and_confirmation_follow_the_same_lock_order(): void
    {
        $command = new StockPostingCommand($this->documentVariant, $this->documentBranch, StockMovementType::Purchase, '10', 'opening');
        app(InventoryPostingService::class)->post($command, $this->documentActor);
        $order = $this->sale();
        $inventory = ['type' => 'inventory', 'variant' => $this->documentVariant->id, 'location' => $this->documentBranch->id,
            'identity' => 'concurrent-inventory', 'actor' => $this->documentActor->id];
        $this->assertCompleted($this->pair($inventory, $this->operation('confirm', $order)));
        $this->assertBalance($this->documentVariant, $this->documentBranch, '11', '3');
        $this->assertSame('confirmed', $order->refresh()->status);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(0, InventoryCostEntry::count());
        $this->assertSame(0, InventoryCostState::count());
        $this->assertSame(0, FifoLayer::count());
        $this->assertNumbering();
    }

    public static function workflows(): iterable
    {
        foreach (['fifo', 'avco', 'standard'] as $method) {
            foreach (['receipt-confirmation', 'delivery-receipt', 'allocation-issue', 'two-receipts', 'different-branches', 'duplicate-delivery'] as $scenario) {
                yield $method.' '.$scenario => [$method, $scenario];
            }
        }
    }

    #[DataProvider('workflows')]
    public function test_workflow_contention_preserves_stock_valuation_and_replay(string $method, string $scenario): void
    {
        InventoryValuationSetting::where('category_id', $this->documentVariant->product->category_id)->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $this->standard($this->documentVariant);
        }
        $this->receipt('10');
        $source = null;
        $other = null;
        switch ($scenario) {
            case 'receipt-confirmation':
                $first = $this->operation('receipt', $this->receipt('4', post: false));
                $order = $this->sale();
                $second = $this->operation('confirm', $order);
                $quantity = '14';
                $reserved = '3';
                $movements = 2;
                break;
            case 'delivery-receipt':
                $first = $this->operation('delivery', $this->delivery());
                $second = $this->operation('receipt', $this->receipt('4', post: false));
                $quantity = '11';
                $movements = 3;
                break;
            case 'allocation-issue':
                $source = app(UnallocatedVariantService::class)->ensure($this->documentActor, $this->documentVariant->product);
                if ($method === 'standard') {
                    $this->standard($source);
                }
                $this->receipt('6', $source);
                $first = $this->operation('allocation', $this->allocation($source));
                $second = $this->operation('delivery', $this->delivery());
                $quantity = '9';
                $movements = 5;
                break;
            case 'two-receipts':
                $first = $this->operation('receipt', $this->receipt('2', post: false));
                $second = $this->operation('receipt', $this->receipt('4', post: false));
                $quantity = '16';
                $movements = 3;
                break;
            case 'different-branches':
                $other = Location::create(['name' => 'Other lock-order branch', 'type' => 'branch', 'is_active' => true]);
                $this->receipt('10', branch: $other);
                $first = $this->operation('receipt', $this->receipt('4', post: false));
                $second = $this->operation('receipt', $this->receipt('2', branch: $other, post: false));
                $quantity = '14';
                $movements = 4;
                break;
            case 'duplicate-delivery':
                $first = $this->operation('delivery', $this->delivery());
                $second = $first;
                $quantity = '7';
                $movements = 2;
                break;
        }
        $results = $this->pair($first, $second);
        $this->assertCompleted($results);
        $this->assertBalance($this->documentVariant, $this->documentBranch, $quantity, $reserved ?? '0');
        if ($source !== null) {
            $this->assertBalance($source, $this->documentBranch, '4');
        }
        if ($other !== null) {
            $this->assertBalance($this->documentVariant, $other, '12');
        }
        if ($scenario === 'duplicate-delivery') {
            $this->assertSame($results[0]['id'], $results[1]['id']);
        }
        foreach ([$first, $second] as $operation) {
            $table = match ($operation['type']) {
                'confirm' => 'sales_orders', 'receipt' => 'purchase_receipts',
                'allocation' => 'inventory_size_allocations', 'delivery' => 'sales_deliveries',
            };
            $this->assertDatabaseHas($table, ['id' => $operation['id'], 'status' => $operation['type'] === 'confirm' ? 'confirmed' : 'posted']);
        }
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame($movements, InventoryCostEntry::count());
        $this->assertValuation($method);
        $this->assertNumbering();
        $before = $this->snapshot();
        foreach ([$first, $second] as $operation) {
            $this->replay($operation);
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function replay(array $operation): void
    {
        $id = $operation['id'];
        match ($operation['type']) {
            'receipt' => app(PurchaseReceiptService::class)->post($this->documentActor, PurchaseReceipt::findOrFail($id), $operation['revision']),
            'confirm' => app(SalesOrderConfirmationService::class)->confirm($this->documentActor, SalesOrder::findOrFail($id), $operation['revision']),
            'delivery' => app(SalesDeliveryPostingService::class)->post($this->documentActor, SalesDelivery::findOrFail($id), $operation['revision']),
            'allocation' => app(SizeAllocationService::class)->post($this->documentActor, SizeAllocation::findOrFail($id), $operation['revision']),
        };
    }

    private function assertValuation(string $method): void
    {
        foreach (StockBalance::all() as $balance) {
            $state = InventoryCostState::where('variant_id', $balance->variant_id)->where('location_id', $balance->location_id)->firstOrFail();
            $this->assertSame($balance->quantity_on_hand, $state->on_hand_qty);
            $this->assertSame((string) BigDecimal::of($state->on_hand_qty)->multipliedBy('2')->toScale(4), $state->total_value);
            $this->assertSame('2.000000', $state->avg_cost);
            $reserved = DB::table('sales_stock_reservations')->where('variant_id', $balance->variant_id)->where('location_id', $balance->location_id)
                ->selectRaw('COALESCE(SUM(quantity_reserved - quantity_consumed - quantity_released), 0) AS active')->first()->active;
            $this->assertTrue(BigDecimal::of($balance->quantity_reserved)->isEqualTo($reserved));
            if ($method === 'fifo') {
                $layers = FifoLayer::where('variant_id', $balance->variant_id)->whereHas('stockMovement', fn ($query) => $query->where('location_id', $balance->location_id))->get();
                $quantity = BigDecimal::zero();
                $value = BigDecimal::zero();
                foreach ($layers as $layer) {
                    $quantity = $quantity->plus($layer->remaining_qty);
                    $value = $value->plus(app(FifoLayerValue::class)->remaining($layer));
                }
                $this->assertTrue($quantity->isEqualTo($state->on_hand_qty));
                $this->assertTrue($value->isEqualTo($state->total_value));
            }
        }
        foreach (FifoLayerConsumption::all() as $part) {
            $this->assertTrue(BigDecimal::of($part->quantity)->multipliedBy('2')->isEqualTo($part->total_cost));
        }
        if ($method !== 'fifo') {
            $this->assertSame(0, FifoLayer::count());
            $this->assertSame(0, FifoLayerConsumption::count());
        }
        $this->assertSame(0, InventoryCostEntry::where('method', '!=', $method)->count());
        $this->assertSame(0, StockMovement::whereNull('posting_payload_hash')->count());
        $this->assertSame(StockMovement::count(), StockMovement::distinct()->count('idempotency_key'));
        if ($method === 'standard') {
            $this->assertSame(0, InventoryCostEntry::whereNull('standard_cost_revision_id')->count());
        }
    }

    private function assertNumbering(): void
    {
        $this->assertSame(StockMovement::count(), DB::table('document_numbers')->where('document_type', 'SM')->count());
        foreach (DB::table('document_number_sequences')->get() as $sequence) {
            $numbers = DB::table('document_numbers')->where('location_id', $sequence->location_id)->where('document_type', $sequence->document_type);
            $this->assertSame((int) $sequence->last_number, (int) $numbers->max('sequence_value'));
            $this->assertSame((int) $sequence->last_number, $numbers->count());
        }
        foreach (StockMovement::all() as $movement) {
            $this->assertDatabaseHas('document_numbers', ['document_type' => 'SM', 'document_uuid' => $movement->uuid, 'location_id' => $movement->location_id]);
        }
    }

    private function snapshot(): array
    {
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers', 'fifo_layer_consumptions',
            'inventory_standard_cost_revisions', 'document_numbers', 'document_number_sequences', 'sales_orders', 'sales_stock_reservations',
            'sales_reservation_consumptions', 'sales_deliveries', 'sales_delivery_items', 'purchase_orders', 'purchase_receipts',
            'purchase_receipt_items', 'inventory_size_allocations', 'inventory_size_allocation_items'];

        return collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_standard_revision_edit_cannot_invert_allocation_valuation_locks(): void
    {
        InventoryValuationSetting::where('category_id', $this->documentVariant->product->category_id)->update(['costing_method' => 'standard']);
        $this->standard($this->documentVariant);
        $source = app(UnallocatedVariantService::class)->ensure($this->documentActor, $this->documentVariant->product);
        $revision = $this->standard($source);
        $this->receipt('6', $source);
        $allocation = $this->allocation($source);
        $before = $revision->refresh()->getAttributes();
        $first = $this->operation('allocation', $allocation) + ['hold' => 'cost-entry'];
        $second = ['type' => 'revision', 'id' => $revision->id, 'actor' => $this->documentActor->id];
        $results = $this->pair($first, $second);
        $this->assertSame(['completed', 'rejected'], array_column($results, 'status'), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('registry', $results[1]['locks'][0]);
        $this->assertSame($before, $revision->refresh()->getAttributes());
        $this->assertSame('posted', $allocation->refresh()->status);
        $this->assertBalance($source, $this->documentBranch, '4');
        $this->assertBalance($this->documentVariant, $this->documentBranch, '2');
        $this->assertValuation('standard');
        $this->assertNumbering();
    }

    public function test_controlled_opposite_order_exposes_a_real_circular_wait(): void
    {
        $this->receipt('10');
        $before = $this->snapshot();
        $operation = ['variant' => $this->documentVariant->id, 'location' => $this->documentBranch->id, 'actor' => $this->documentActor->id];
        $results = $this->pair(['type' => 'raw-balance-first'] + $operation, ['type' => 'raw-registry-first'] + $operation);
        $this->assertEqualsCanonicalizing(['completed', 'error'], array_column($results, 'status'));
        $errors = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'error'));
        $this->assertSame(1213, $errors[0]['driver_code']);
        $this->assertSame($before, $this->snapshot());
    }
}
