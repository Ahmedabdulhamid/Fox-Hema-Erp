<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = 'codex_public_ids_test_purchasing_vendor_payments_'.getmypid().'_'.bin2hex(random_bytes(4));
$host = getenv('VENDOR_PAYMENT_TEST_HOST') ?: '127.0.0.1';
$port = getenv('VENDOR_PAYMENT_TEST_PORT') ?: (getenv('DB_PORT') ?: '3306');
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! ctype_digit($port)) {
    throw new LogicException('Vendor payment tests require a local isolated database server.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => getenv('VENDOR_PAYMENT_TEST_USER') ?: 'root',
    'DB_PASSWORD' => getenv('VENDOR_PAYMENT_TEST_PASSWORD') ?: '', 'DB_URL' => '', 'DB_SOCKET' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/vendor-payments-test-no-config-cache.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$pdo = new PDO('mysql:host='.$host.';port='.$port, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($pdo, $database): void {
    $pdo->exec('DROP DATABASE `'.$database.'`');
});
echo 'Isolated vendor payments test schema: '.$database.PHP_EOL;
