<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Services\VendorPaymentAllocationService;
use App\Modules\Purchasing\Services\VendorPaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database) || ! preg_match('/^codex_public_ids_test_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-vendor-payments-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$actor = User::findOrFail((int) getenv('VP_ACTOR'));
setPermissionsTeamId((int) getenv('VP_LOCATION'));
while (microtime(true) < (float) getenv('VP_START')) {
    usleep(1000);
}
$payments = app(VendorPaymentService::class);
try {
    $operation = getenv('VP_OPERATION');
    $record = match ($operation) {
        'create', 'conflict' => $payments->createDraft($actor, ['supplier_id' => (int) getenv('VP_SUPPLIER'),
            'location_id' => (int) getenv('VP_LOCATION'), 'payment_method_id' => (int) getenv('VP_METHOD'),
            'treasury_account_id' => (int) getenv('VP_ACCOUNT'), 'payment_date' => '2026-10-24', 'amount' => $operation === 'conflict' && getenv('VP_WORKER') === '1' ? '2' : '100'], getenv('VP_KEY')),
        'post' => $payments->post($actor, VendorPayment::findOrFail((int) getenv('VP_PAYMENT'))),
        'allocate' => app(VendorPaymentAllocationService::class)->allocate($actor, VendorPayment::findOrFail((int) getenv('VP_PAYMENT')),
            VendorBill::findOrFail((int) getenv('VP_BILL')), '100', getenv('VP_KEY')),
        'reverse' => $payments->recover($actor, VendorPayment::findOrFail((int) getenv('VP_PAYMENT')), getenv('VP_KEY'), 'Concurrent correction', 'Verified concurrent test recovery'),
    };
    echo json_encode(['status' => 'ok', 'id' => $record->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'rejected'], JSON_THROW_ON_ERROR);
}
