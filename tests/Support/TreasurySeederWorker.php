<?php

use App\Modules\Treasury\Database\Seeders\TreasuryDatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = getenv('DB_DATABASE');
if (getenv('APP_ENV') !== 'testing' || ! is_string($database)
    || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)) {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.isolated-treasury-tests');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $database
    || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    exit(2);
}
setPermissionsTeamId(null);
while (microtime(true) < (float) getenv('TREASURY_SEED_START')) {
    usleep(1000);
}
$result = $kernel->call('db:seed', ['--class' => TreasuryDatabaseSeeder::class, '--force' => true, '--no-interaction' => true]);
echo json_encode(['status' => $result === 0 ? 'ok' : 'failed', 'output' => $kernel->output()], JSON_THROW_ON_ERROR);
exit($result);
