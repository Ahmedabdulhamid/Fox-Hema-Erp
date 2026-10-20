<?php

/** Only this dedicated test runner creates a schema; it never selects an application database. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = getenv('DB_DATABASE');
if ($database === false || $database === '') {
    $database = 'codex_public_ids_test_purchasing_'.getmypid().'_'.bin2hex(random_bytes(4));
}
if (! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', $database)) {
    throw new LogicException('Refusing a database outside the isolated purchasing test prefix.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/phase3-no-config-cache.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$pdo = new PDO('mysql:host='.(getenv('DB_HOST') ?: '127.0.0.1').';port='.(getenv('DB_PORT') ?: '3306'),
    getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (! str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
    throw new LogicException('The purchasing integration runner requires MariaDB.');
}
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo 'Isolated MariaDB test schema: '.$database.PHP_EOL;
