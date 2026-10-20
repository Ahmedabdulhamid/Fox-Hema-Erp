<?php

use App\Modules\AccessManagement\Services\DocumentNumberService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || ! str_starts_with($connection->getDatabaseName(), 'codex_public_ids_test_')
    || $connection->selectOne('SELECT DATABASE() AS db')->db !== $connection->getDatabaseName()) {
    throw new LogicException('Refusing a database outside the isolated test prefix.');
}
while (microtime(true) < (float) getenv('DOCUMENT_START')) {
    usleep(1000);
}
$number = DB::transaction(fn () => app(DocumentNumberService::class)
    ->allocate((int) getenv('DOCUMENT_LOCATION'), 'SO', getenv('DOCUMENT_UUID')), 3);
echo json_encode(['number' => $number], JSON_THROW_ON_ERROR);
