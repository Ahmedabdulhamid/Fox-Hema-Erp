<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$host = getenv('STOCK_CONSTRAINT_TEST_HOST');
$port = getenv('STOCK_CONSTRAINT_TEST_PORT');
$engine = getenv('STOCK_CONSTRAINT_TEST_ENGINE');
$identityHash = getenv('STOCK_CONSTRAINT_TEST_ID');
$suite = getenv('STOCK_CONSTRAINT_TEST_SUITE');
if (getenv('APP_ENV') !== 'testing' || $host !== '127.0.0.1' || ! in_array($port, ['33316', '33317'], true)
    || ! in_array($engine, ['mysql', 'mariadb'], true) || ! in_array($suite, ['purchasing', 'phase0'], true)
    || ! preg_match('/^[a-f0-9]{64}$/D', (string) $identityHash)) {
    throw new LogicException('Stock regressions require an independently verified disposable server and suite.');
}
$pdo = new PDO('mysql:host='.$host.';port='.$port, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 3, PDO::ATTR_STRINGIFY_FETCHES => true]);
$identity = $pdo->query('SELECT VERSION() AS version, @@hostname AS hostname, @@port AS port, @@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if (hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) !== $identityHash
    || str_contains(strtolower($identity['version']), 'mariadb') !== ($engine === 'mariadb')) {
    throw new LogicException('Disposable regression server identity changed.');
}
$database = 'codex_public_ids_test_'.$suite.'_'.bin2hex(random_bytes(6));
if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}'")->fetchColumn() !== 0) {
    throw new LogicException('Regression schema must be new.');
}
$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($pdo, $database): void {
    $pdo->exec('DROP DATABASE '.$database);
});
$pdo->exec('USE '.$database);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database
    || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
    throw new LogicException('Regression schema is not the verified new empty target.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DB_URL' => '', 'DB_SOCKET' => '',
    'PHASE0_DATABASE' => $database, 'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/'.$database.'-config.php',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(getenv('APP_CONFIG_CACHE'))) {
    throw new LogicException('Regression tests must not load cached configuration.');
}
echo 'Verified '.$identity['version'].' on '.$host.':'.$port.'; new empty schema '.$database.PHP_EOL;
