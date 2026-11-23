<?php

use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database)
    || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-treasury-tests');
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
    || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
$actor = User::findOrFail((int) getenv('TREASURY_ACTOR'));
setPermissionsTeamId((int) getenv('TREASURY_LOCATION'));
$service = app(TreasuryService::class);
$mode = getenv('TREASURY_MODE');
if ($mode === 'snapshot') {
    DB::beginTransaction();
    DB::table('treasury_entries')->count();
    echo "ready\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'continue') {
        exit(2);
    }
}
while (microtime(true) < (float) getenv('TREASURY_START')) {
    usleep(1000);
}
try {
    if ($mode === 'reverse') {
        $operation = $service->reverse($actor, TreasuryOperation::findOrFail((int) getenv('TREASURY_OPERATION')),
            getenv('TREASURY_KEY'), 'Concurrent correction');
    } else {
        $data = [
            'type' => getenv('TREASURY_TYPE'), 'account_id' => (int) getenv('TREASURY_ACCOUNT'),
            'amount' => getenv('TREASURY_AMOUNT'), 'effective_date' => now()->format('Y-m-d'),
            'source_type' => 'external_other', 'source_reference' => getenv('TREASURY_KEY'),
            'reason' => 'Concurrent controlled operation',
        ];
        if (getenv('TREASURY_DESTINATION')) {
            $data['destination_account_id'] = (int) getenv('TREASURY_DESTINATION');
        }
        $operation = $service->post($actor, $service->createDraft($actor, $data, getenv('TREASURY_KEY')));
    }
    if ($mode === 'snapshot') {
        DB::commit();
    }
    echo json_encode(['status' => 'ok', 'id' => $operation->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    if ($mode === 'snapshot') {
        DB::rollBack();
    }
    echo json_encode(['status' => 'rejected'], JSON_THROW_ON_ERROR);
}
