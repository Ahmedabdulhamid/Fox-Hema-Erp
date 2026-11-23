<?php

use App\Modules\Products\Models\Category;
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
while (microtime(true) < (float) getenv('SHORT_CODE_START')) {
    usleep(1000);
}
$category = Category::create(['name' => 'Concurrent generated category '.getenv('SHORT_CODE_NAME')]);
echo json_encode(['code' => $category->code], JSON_THROW_ON_ERROR);
