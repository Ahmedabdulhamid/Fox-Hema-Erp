<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\CustomerRefunds;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database) || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-customer-settlement-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$actor = User::findOrFail((int) getenv('CUSTOMER_ACTOR'));
setPermissionsTeamId((int) getenv('CUSTOMER_LOCATION'));
$snapshot = getenv('CUSTOMER_SNAPSHOT') === '1';
if ($snapshot) {
    DB::beginTransaction();
    DB::table('payment_allocations')->count();
    echo "ready\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'continue') {
        exit(2);
    }
}
while (microtime(true) < (float) getenv('CUSTOMER_START')) {
    usleep(1000);
}
try {
    $mode = getenv('CUSTOMER_MODE');
    $key = getenv('CUSTOMER_KEY');
    if ($mode === 'invoice-payment') {
        $result = app(CustomerPayments::class)->payInvoice($actor, SalesInvoice::findOrFail((int) getenv('CUSTOMER_INVOICE')), json_decode(getenv('CUSTOMER_DATA'), true, flags: JSON_THROW_ON_ERROR), $key);
    } elseif ($mode === 'credit-refund') {
        $result = app(CustomerRefunds::class)->refundCreditNote($actor, SalesCreditNote::findOrFail((int) getenv('CUSTOMER_CREDIT')), json_decode(getenv('CUSTOMER_DATA'), true, flags: JSON_THROW_ON_ERROR), $key);
    } elseif ($mode === 'create') {
        $result = app(CustomerPayments::class)->createDraft($actor, json_decode(getenv('CUSTOMER_DATA'), true, flags: JSON_THROW_ON_ERROR), $key);
    } elseif ($mode === 'post') {
        $result = app(CustomerPayments::class)->post($actor, Payment::findOrFail((int) getenv('CUSTOMER_PAYMENT')));
    } elseif ($mode === 'allocate') {
        $result = app(CustomerAllocations::class)->allocate($actor, Payment::findOrFail((int) getenv('CUSTOMER_PAYMENT')), SalesInvoice::findOrFail((int) getenv('CUSTOMER_INVOICE')), getenv('CUSTOMER_AMOUNT') ?: '80', $key);
    } else {
        $service = app(CustomerRefunds::class);
        $result = $service->post($actor, $service->createDraft($actor, ['payment_id' => (int) getenv('CUSTOMER_PAYMENT'), 'amount' => '60', 'refund_date' => now()->toDateString(), 'reason' => 'Concurrent refund'], $key));
    }
    if ($snapshot) {
        DB::commit();
    }
    echo json_encode(['status' => 'ok', 'id' => $result->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    if ($snapshot) {
        DB::rollBack();
    }
    echo json_encode(['status' => 'rejected', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
}
