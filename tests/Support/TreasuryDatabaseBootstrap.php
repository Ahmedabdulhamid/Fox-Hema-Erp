<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = 'codex_public_ids_test_treasury_'.getmypid().'_'.bin2hex(random_bytes(4));
$host = getenv('TREASURY_TEST_HOST');
$port = getenv('TREASURY_TEST_PORT');
$host = $host === false || $host === '' ? '127.0.0.1' : $host;
$port = $port === false || $port === '' ? '33316' : $port;
if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! ctype_digit($port)
    || (int) $port < 1 || (int) $port > 65535) {
    throw new LogicException('Treasury tests require a local isolated database server.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => getenv('TREASURY_TEST_USER') ?: 'root',
    'DB_PASSWORD' => getenv('TREASURY_TEST_PASSWORD') ?: '', 'DB_URL' => '', 'DB_SOCKET' => '',
    'APP_CONFIG_CACHE' => dirname(__DIR__, 2).'/storage/framework/'.$database.'-config.php'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
if (is_file(getenv('APP_CONFIG_CACHE'))) {
    throw new LogicException('Disposable tests must not load cached application configuration.');
}
try {
    $pdo = new PDO('mysql:host='.$host.';port='.$port, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
    ]);
} catch (PDOException $exception) {
    throw new RuntimeException('Disposable test database unavailable. Start a dedicated local MySQL/MariaDB server and set TREASURY_TEST_HOST/PORT/USER/PASSWORD. No development database fallback is allowed.', 0, $exception);
}
$quote = chr(96);
$pdo->exec('CREATE DATABASE '.$quote.$database.$quote.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use ($pdo, $database, $quote): void {
    $pdo->exec('DROP DATABASE '.$quote.$database.$quote);
});
echo 'Isolated Treasury test schema: '.$database.PHP_EOL;
