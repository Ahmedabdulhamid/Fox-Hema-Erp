<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\VendorBillService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database) || ! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$actor = User::findOrFail((int) getenv('BILL_ACTOR'));
$bill = VendorBill::findOrFail((int) getenv('BILL_ID'));
while (microtime(true) < (float) getenv('BILL_START')) {
    usleep(1000);
}
try {
    $posted = app(VendorBillService::class)->post($actor, $bill, getenv('BILL_REVISION'));
    echo json_encode(['status' => 'posted', 'id' => $posted->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'rejected'], JSON_THROW_ON_ERROR);
}
