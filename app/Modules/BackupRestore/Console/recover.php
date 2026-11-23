<?php

/** Standalone: no .env loading, providers, sessions, queues or application database boot. */
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\StandaloneRecovery;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

require dirname(__DIR__, 4).'/vendor/autoload.php';

try {
    $options = getopt('', ['kit:', 'descriptor:', 'artifact:', 'approve-isolated', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php app/Modules/BackupRestore/Console/recover.php --kit=PRIVATE_JSON --descriptor=TRUSTED_JSON [--artifact=PRIVATE_FHBK] --approve-isolated\n";
        exit(0);
    }
    if (! array_key_exists('approve-isolated', $options) || ! isset($options['kit'], $options['descriptor'])) {
        throw new RuntimeException;
    }
    $environment = getenv('APP_ENV') ?: 'local';
    if (! in_array($environment, ['local', 'testing'], true)) {
        throw new BackupFailure('restore_disabled');
    }
    $app = new Application(dirname(__DIR__, 4));
    $app->instance('env', $environment);
    $settings = require $app->basePath('config/backup-restore.php');
    $app->instance('config', new Repository(['backup-restore' => $settings, 'filesystems' => ['disks' => [
        'public' => ['root' => $app->storagePath('app/public')], 'local' => ['root' => $app->storagePath('app/private')],
    ]]]));
    $app->make(PrivateFiles::class)->assertPrivate($options['kit']);
    $app->make(PrivateFiles::class)->assertPrivate($options['descriptor']);
    if (filesize($options['kit']) > 65536 || filesize($options['descriptor']) > 65536) {
        throw new RuntimeException;
    }
    $kit = json_decode(file_get_contents($options['kit']), true, flags: JSON_THROW_ON_ERROR);
    $descriptor = json_decode(file_get_contents($options['descriptor']), true, flags: JSON_THROW_ON_ERROR);
    if (isset($options['artifact']) && ! is_string($options['artifact'])) {
        throw new BackupFailure('restore_identity_mismatch');
    }
    echo json_encode($app->make(StandaloneRecovery::class)->run($kit, $descriptor, $options['artifact'] ?? null), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $failure) {
    fwrite(STDERR, 'Recovery refused: '.($failure instanceof BackupFailure ? $failure->getMessage() : 'restore_failed').PHP_EOL);
    exit(1);
}
