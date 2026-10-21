<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = 'codex_public_ids_test_purchasing_'.getmypid().'_'.bin2hex(random_bytes(4));
$host = getenv('PURCHASE_ORDER_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('PURCHASE_ORDER_TEST_MYSQL_PORT') ?: '33316';
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! ctype_digit($port)) {
    throw new LogicException('Purchase order MySQL tests require a local isolated server.');
}

foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => getenv('PURCHASE_ORDER_TEST_MYSQL_USER') ?: 'root',
    'DB_PASSWORD' => getenv('PURCHASE_ORDER_TEST_MYSQL_PASSWORD') ?: '', 'DB_URL' => '', 'DB_SOCKET' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/purchase-order-test-no-config-cache.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$pdo = new PDO('mysql:host='.$host.';port='.$port, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
    throw new LogicException('This regression test requires native MySQL JSON normalization.');
}
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($pdo, $database): void {
    $pdo->exec('DROP DATABASE `'.$database.'`');
});
echo 'Isolated MySQL test schema: '.$database.PHP_EOL;
