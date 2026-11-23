<?php

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = 'codex_public_ids_test_inventory_'.getmypid().'_'.bin2hex(random_bytes(4));
$host = getenv('INVENTORY_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('INVENTORY_TEST_MYSQL_PORT') ?: '33316';
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! ctype_digit($port)) {
    throw new LogicException('Inventory tests require a local isolated server.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'INVENTORY_MYSQL_DATABASE' => $database, 'DB_USERNAME' => getenv('INVENTORY_TEST_MYSQL_USER') ?: 'root',
    'DB_PASSWORD' => getenv('INVENTORY_TEST_MYSQL_PASSWORD') ?: '', 'DB_URL' => '', 'DB_SOCKET' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/inventory-test-no-config-cache.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$pdo = new PDO('mysql:host='.$host.';port='.$port, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quote = chr(96);
$pdo->exec('CREATE DATABASE '.$quote.$database.$quote.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($pdo, $database, $quote): void {
    $pdo->exec('DROP DATABASE '.$quote.$database.$quote);
});
echo 'Isolated Inventory MySQL schema: '.$database.PHP_EOL;
$migration = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if ($app->make('db')->connection()->selectOne('SELECT DATABASE() AS db')->db !== getenv('DB_DATABASE')
    || ! str_starts_with(getenv('DB_DATABASE'), 'codex_public_ids_test_inventory_')) {
    throw new LogicException('Isolated database identity mismatch.');
}
$app->make('migrator')->path(base_path('tests/Fixtures/Migrations'));
exit($kernel->call('migrate', ['--force' => true, '--no-interaction' => true]));
PHP], dirname(__DIR__, 2));
$migration->setTimeout(120)->mustRun();
