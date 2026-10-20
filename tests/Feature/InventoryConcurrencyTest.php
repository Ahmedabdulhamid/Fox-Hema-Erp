<?php

namespace Tests\Feature;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\InventoryTestSetup;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use InventoryTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('INVENTORY_MYSQL_DATABASE');
        if (is_string($database) && $database !== '' && ! str_starts_with($database, 'codex_public_ids_test_inventory_')) {
            throw new \LogicException('Refusing an explicitly selected database outside the isolated inventory test prefix.');
        }
        if (! is_string($database) || ! str_starts_with($database, 'codex_public_ids_test_inventory_')) {
            // Discovery is read-only. Never create, wipe, or select an application database.
            config(['database.connections.inventory_probe' => array_merge(config('database.connections.mysql'), ['database' => null, 'url' => null])]);
            try {
                $schemas = DB::connection('inventory_probe')->select('SELECT SCHEMA_NAME AS name FROM information_schema.SCHEMATA');
                $eligible = array_values(array_filter(array_column($schemas, 'name'), fn (string $name): bool => str_starts_with($name, 'codex_public_ids_test_inventory_')));
                sort($eligible);
                $database = $eligible[0] ?? null;
            } catch (\Throwable $exception) {
                $database = null;
            } finally {
                DB::purge('inventory_probe');
            }
            if ($database === null) {
                $this->markTestSkipped('No existing dedicated codex_public_ids_test_inventory_ MySQL database is available. Set INVENTORY_MYSQL_DATABASE after provisioning one.');
            }
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $database, 'database.connections.mysql.url' => null]);
        DB::purge('mysql');
        try {
            $resolved = DB::connection()->selectOne('SELECT DATABASE() AS db')->db;
        } catch (\Throwable $exception) {
            $this->markTestSkipped('The explicitly selected isolated MySQL database is unavailable.');
        }
        $this->assertSame($database, $resolved);
        $this->setUpInventory();
    }

    /** @return list<array{status: string, id?: int}> */
    private function workers(array $operations): array
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $database = $connection->selectOne('SELECT DATABASE() AS db')->db;
        $this->assertStringStartsWith('codex_public_ids_test_inventory_', $database);
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = Illuminate\Support\Facades\DB::connection();
if (!$app->environment('testing') || $c->getDriverName() !== 'mysql' || !str_starts_with($c->getDatabaseName(), 'codex_public_ids_test_inventory_') || $c->selectOne('SELECT DATABASE() AS db')->db !== $c->getDatabaseName()) { exit(2); }
$variant = App\Modules\Products\Models\ProductVariant::findOrFail((int) getenv('INVENTORY_VARIANT'));
$location = App\Modules\AccessManagement\Models\Location::findOrFail((int) getenv('INVENTORY_LOCATION'));
$actor = App\Modules\AccessManagement\Models\User::findOrFail((int) getenv('INVENTORY_ACTOR'));
while (microtime(true) < (float) getenv('INVENTORY_START')) { usleep(1000); }
try {
    $commands = [new App\Modules\Inventory\Data\StockPostingCommand(
        $variant, $location, App\Modules\Inventory\Enums\StockMovementType::from(getenv('INVENTORY_TYPE')),
        getenv('INVENTORY_QUANTITY'), getenv('INVENTORY_IDENTITY')
    )];
    if (getenv('INVENTORY_BATCH_VARIANT') !== '') {
        $other = App\Modules\Products\Models\ProductVariant::findOrFail((int) getenv('INVENTORY_BATCH_VARIANT'));
        $commands[] = new App\Modules\Inventory\Data\StockPostingCommand(
            $other, $location, App\Modules\Inventory\Enums\StockMovementType::Purchase,
            getenv('INVENTORY_QUANTITY'), getenv('INVENTORY_IDENTITY').':second'
        );
        if (getenv('INVENTORY_REVERSE_INPUT') === '1') { $commands = array_reverse($commands); }
    }
    $movement = app(App\Modules\Inventory\Services\InventoryPostingService::class)->postMany($commands, $actor)[0];
    echo json_encode(['status' => 'posted', 'id' => $movement->id]);
} catch (Illuminate\Validation\ValidationException $exception) {
    echo json_encode(['status' => 'rejected', 'errors' => $exception->errors()]);
}
PHP;
        $config = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'INVENTORY_VARIANT' => (string) $this->variant->id, 'INVENTORY_LOCATION' => (string) $this->location->id,
            'INVENTORY_ACTOR' => (string) $this->actor->id, 'INVENTORY_START' => (string) (microtime(true) + 1),
        ];
        $workers = [];
        try {
            foreach ($operations as $operation) {
                [$identity, $quantity, $type] = $operation;
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), array_merge($environment, [
                    'INVENTORY_IDENTITY' => $identity, 'INVENTORY_QUANTITY' => $quantity, 'INVENTORY_TYPE' => $type,
                    'INVENTORY_BATCH_VARIANT' => (string) ($operation[3] ?? ''),
                    'INVENTORY_REVERSE_INPUT' => ($operation[4] ?? false) ? '1' : '0',
                ]));
                $process->setTimeout(30)->start();
                $workers[] = $process;
            }
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                // Do not expose worker configuration/credentials on failure.
                $this->assertTrue($process->isSuccessful(), 'Isolated inventory worker failed; inspect its failure locally.');
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_concurrent_duplicate_first_row_posting_changes_stock_once(): void
    {
        $key = 'duplicate-'.$this->variant->uuid;
        $results = $this->workers([[$key, '5', 'purchase'], [$key, '5.0000', 'purchase']]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame('5.0000', $this->balance()->quantity_on_hand);
        $this->assertSame(1, StockMovement::where('variant_id', $this->variant->id)->count());
        $this->assertSame(1, StockBalance::where('variant_id', $this->variant->id)->count());
    }

    public function test_concurrent_final_unit_sales_cannot_oversell(): void
    {
        $this->postStock('1', 'opening-'.$this->variant->uuid);
        $results = $this->workers([
            ['cashier-a-'.$this->variant->uuid, '-1', 'sale'],
            ['cashier-b-'.$this->variant->uuid, '-1', 'sale'],
        ]);
        $this->assertEqualsCanonicalizing(['posted', 'rejected'], array_column($results, 'status'));
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
        $this->assertSame(1, StockMovement::where('variant_id', $this->variant->id)->where('type', StockMovementType::Sale)->count());
    }

    public function test_concurrent_different_payloads_with_same_key_conflict(): void
    {
        $key = 'conflict-'.$this->variant->uuid;
        $results = $this->workers([[$key, '2', 'purchase'], [$key, '3', 'purchase']]);
        $this->assertEqualsCanonicalizing(['posted', 'rejected'], array_column($results, 'status'));
        $this->assertSame(1, StockMovement::where('variant_id', $this->variant->id)->count());
        $this->assertContains($this->balance()->quantity_on_hand, ['2.0000', '3.0000']);
    }

    public function test_mysql_unique_operation_and_balance_keys_are_enforced(): void
    {
        $movement = $this->postStock('1', 'unique-'.$this->variant->uuid);
        $this->rejectInventory(fn () => StockMovement::create([
            'variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'type' => 'purchase', 'quantity' => '1', 'idempotency_key' => $movement->idempotency_key,
        ]), UniqueConstraintViolationException::class);
        $this->rejectInventory(fn () => StockBalance::create([
            'variant_id' => $this->variant->id, 'location_id' => $this->location->id,
        ]), UniqueConstraintViolationException::class);
    }

    public function test_concurrent_batches_with_opposite_input_order_use_the_same_lock_order(): void
    {
        $other = ProductVariant::factory()->create();
        $results = $this->workers([
            ['batch-a-'.$this->variant->uuid, '2', 'purchase', $other->id, false],
            ['batch-b-'.$this->variant->uuid, '3', 'purchase', $other->id, true],
        ]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'status'));
        $this->assertSame('5.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('5.0000', StockBalance::where('variant_id', $other->id)->where('location_id', $this->location->id)->firstOrFail()->quantity_on_hand);
    }
}
