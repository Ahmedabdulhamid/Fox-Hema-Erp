<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database)
    || ! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
    || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
while (microtime(true) < (float) getenv('SALES_START')) {
    usleep(1000);
}
try {
    $actor = User::findOrFail((int) getenv('SALES_ACTOR'));
    $operation = getenv('SALES_OPERATION') ?: 'create';
    if ($operation === 'return-create') {
        app(LocationContext::class)->setFor($actor, (int) getenv('SALES_BRANCH'));
        $order = app(SalesReturnService::class)->create($actor,
            json_decode(getenv('SALES_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR), getenv('SALES_SUBMISSION'));
    } elseif ($operation === 'return-post') {
        app(LocationContext::class)->setFor($actor, (int) getenv('SALES_BRANCH'));
        $order = app(SalesReturnPostingService::class)->post($actor,
            SalesReturn::findOrFail((int) getenv('SALES_RETURN')), getenv('SALES_REVISION'));
    } elseif (in_array($operation, ['return-approve', 'return-cancel'], true)) {
        app(LocationContext::class)->setFor($actor, (int) getenv('SALES_BRANCH'));
        $document = SalesReturn::findOrFail((int) getenv('SALES_RETURN'));
        $service = app(SalesReturnApprovalService::class);
        $order = $operation === 'return-approve'
            ? $service->approve($actor, $document, getenv('SALES_REVISION'))
            : $service->cancel($actor, $document, getenv('SALES_REVISION'), 'Concurrent cancellation');
    } elseif ($operation === 'credit-post') {
        app(LocationContext::class)->setFor($actor, (int) getenv('SALES_BRANCH'));
        $order = app(SalesCreditNoteService::class)->post($actor,
            SalesCreditNote::findOrFail((int) getenv('SALES_CREDIT')), getenv('SALES_REVISION'));
    } elseif ($operation === 'invoice-post') {
        $order = app(SalesInvoiceService::class)->post($actor,
            SalesInvoice::findOrFail((int) getenv('SALES_INVOICE')), getenv('SALES_REVISION'));
    } elseif ($operation === 'invoice-create') {
        $order = app(SalesInvoiceService::class)->create($actor,
            json_decode(getenv('SALES_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR), getenv('SALES_SUBMISSION'));
    } elseif ($operation === 'delivery-post') {
        $order = app(SalesDeliveryPostingService::class)->post($actor,
            SalesDelivery::findOrFail((int) getenv('SALES_DELIVERY')), getenv('SALES_REVISION'));
    } elseif ($operation === 'delivery-update') {
        $order = app(SalesDeliveryService::class)->update($actor,
            SalesDelivery::findOrFail((int) getenv('SALES_DELIVERY')),
            json_decode(getenv('SALES_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR), getenv('SALES_REVISION'));
    } elseif (in_array($operation, ['confirm', 'cancel'], true)) {
        $order = app(SalesOrderConfirmationService::class)->{$operation}($actor,
            SalesOrder::findOrFail((int) getenv('SALES_ORDER')), getenv('SALES_REVISION') ?: null);
    } elseif ($operation === 'create') {
        $order = app(SalesOrderService::class)->create($actor,
            json_decode(getenv('SALES_PAYLOAD'), true, flags: JSON_THROW_ON_ERROR), getenv('SALES_SUBMISSION'));
    } else {
        exit(2);
    }
    echo json_encode(['id' => $order->id, 'number' => $order->order_number ?? $order->delivery_number ?? $order->invoice_number ?? $order->return_number], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    if (getenv('SALES_ALLOW_REJECTION') !== '1') {
        throw $exception;
    }
    echo json_encode(['rejected' => $exception->errors()], JSON_THROW_ON_ERROR);
}
