<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database) || ! preg_match('/^codex_public_ids_test_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-payments-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$actor = User::findOrFail((int) getenv('PAYMENT_ACTOR'));
setPermissionsTeamId((int) getenv('PAYMENT_LOCATION'));
while (microtime(true) < (float) getenv('PAYMENT_START')) {
    usleep(1000);
}
$service = app(PaymentService::class);
try {
    $operation = getenv('PAYMENT_OPERATION');
    $payment = match ($operation) {
        'create', 'conflict' => $service->createDraft($actor, [
            'location_id' => (int) getenv('PAYMENT_LOCATION'), 'payment_method_id' => (int) getenv('PAYMENT_METHOD'),
            'direction' => 'in', 'amount' => $operation === 'conflict' && getenv('PAYMENT_WORKER') === '1' ? '2.0000' : '1.0000',
        ], getenv('PAYMENT_KEY')),
        'post' => $service->post($actor, Payment::findOrFail((int) getenv('PAYMENT_ID'))),
        'reverse' => $service->reverse($actor, Payment::findOrFail((int) getenv('PAYMENT_ID')), getenv('PAYMENT_KEY'), 'Concurrent correction'),
    };
    echo json_encode(['status' => 'ok', 'id' => $payment->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'rejected'], JSON_THROW_ON_ERROR);
}
