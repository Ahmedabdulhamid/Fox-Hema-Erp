<?php

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = getenv('DB_DATABASE');
$cachePath = getenv('APP_CONFIG_CACHE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database)
    || ! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', $database)
    || ! is_string($cachePath) || $cachePath === '' || file_exists($cachePath)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
$app->make(Kernel::class)->bootstrap();
$server = DB::selectOne('SELECT DATABASE() AS db, @@datadir AS directory, @@port AS port, CONNECTION_ID() AS connection_id');
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || $server->db !== $database
    || rtrim(str_replace('\\', '/', $server->directory), '/') !== rtrim(str_replace('\\', '/', getenv('LOCK_ORDER_EXPECTED_DATADIR')), '/')
    || (string) $server->port !== getenv('LOCK_ORDER_EXPECTED_PORT')) {
    exit(2);
}

$operation = json_decode(getenv('LOCK_ORDER_OPERATION'), true, flags: JSON_THROW_ON_ERROR);
$directory = getenv('LOCK_ORDER_BARRIER');
$name = getenv('LOCK_ORDER_WORKER');
$signal = static function (string $event, array $data = []) use ($directory, $name): void {
    $path = $directory.'/'.$name.'-'.$event.'.json';
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
    rename($path.'.tmp', $path);
};
$actor = User::findOrFail((int) $operation['actor']);
$id = (int) ($operation['id'] ?? 0);
$document = match ($operation['type']) {
    'confirm' => SalesOrder::findOrFail($id),
    'receipt' => PurchaseReceipt::findOrFail($id),
    'delivery' => SalesDelivery::findOrFail($id),
    'allocation' => SizeAllocation::findOrFail($id),
    'revision' => InventoryStandardCostRevision::findOrFail($id),
    default => null,
};
$variant = $operation['type'] === 'inventory' ? ProductVariant::findOrFail($operation['variant']) : null;
$location = $operation['type'] === 'inventory' ? Location::findOrFail($operation['location']) : null;
$app->setLocale('en');
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$signal('ready', ['connection_id' => (int) $server->connection_id]);
$held = false;
$trace = [];
DB::listen(static function (QueryExecuted $event) use (&$held, &$trace, $signal, $operation, $directory, $name): void {
    $sql = strtolower($event->sql);
    $resource = null;
    if (str_contains($sql, '`modules`') && str_contains($sql, 'for update')) {
        $resource = 'registry';
    } elseif (str_contains($sql, '`stock_balances`') && (str_contains($sql, 'for update') || str_starts_with($sql, 'insert'))) {
        $resource = 'balance';
    } elseif (str_starts_with($sql, 'select') && str_contains($sql, '`inventory_cost_entries`') && str_contains($sql, 'for update')) {
        $resource = 'cost-entry';
    } elseif (str_starts_with($sql, 'select') && str_contains($sql, '`inventory_standard_costs`') && str_contains($sql, 'for update')) {
        $resource = 'standard';
    }
    if ($resource === null) {
        return;
    }
    $trace[] = $resource;
    if ($name !== 'a' || $held || (($operation['hold'] ?? 'first') !== 'first' && $operation['hold'] !== $resource)) {
        return;
    }
    $held = true;
    $signal('held', ['resource' => $resource]);
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/release-a') && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! is_file($directory.'/release-a')) {
        throw new RuntimeException('Timed out waiting for the lock-order test barrier.');
    }
});

try {
    // The test owns a complete outer transaction. One attempt exposes a real deadlock rather than hiding it with retries.
    $result = DB::transaction(static function () use ($operation, $actor, $document, $variant, $location): array {
        $revision = $operation['revision'] ?? '';
        switch ($operation['type']) {
            case 'inventory':
                $document = app(InventoryPostingService::class)->post(new StockPostingCommand(
                    $variant, $location,
                    StockMovementType::Purchase, '1', $operation['identity'],
                ), $actor);
                break;
            case 'confirm':
                $document = app(SalesOrderConfirmationService::class)->confirm($actor, $document, $revision);
                break;
            case 'receipt':
                $document = app(PurchaseReceiptService::class)->post($actor, $document, $revision);
                break;
            case 'delivery':
                $document = app(SalesDeliveryPostingService::class)->post($actor, $document, $revision);
                break;
            case 'allocation':
                $document = app(SizeAllocationService::class)->post($actor, $document, $revision);
                break;
            case 'revision':
                $document->update(['reason' => 'Concurrent edit must be rejected']);
                break;
            case 'raw-balance-first':
            case 'raw-registry-first':
                $balance = static fn () => StockBalance::where('variant_id', $operation['variant'])->where('location_id', $operation['location'])->lockForUpdate()->firstOrFail();
                if ($operation['type'] === 'raw-balance-first') {
                    $balance();
                    ModuleRegistryLock::acquire();
                } else {
                    ModuleRegistryLock::acquire();
                    $balance();
                }

                return ['status' => 'completed'];
            default:
                throw new LogicException('Unknown lock-order test operation.');
        }

        return ['status' => 'completed', 'id' => $document->id, 'document_status' => $document->status];
    }, 1);
} catch (ValidationException $exception) {
    $result = ['status' => 'rejected', 'fields' => array_keys($exception->errors())];
} catch (Throwable $exception) {
    $driverCode = null;
    for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException && isset($cause->errorInfo[1])) {
            $driverCode = (int) $cause->errorInfo[1];
        }
    }
    $result = ['status' => 'error', 'exception' => $exception::class, 'driver_code' => $driverCode];
}
echo json_encode($result + ['locks' => $trace, 'connection_id' => (int) $server->connection_id], JSON_THROW_ON_ERROR);
