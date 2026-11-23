<?php

namespace App\Modules\BackupRestore\Services;

use Aws\S3\S3Client;

/** Called with a minimal container, never the original Laravel application bootstrap. */
class StandaloneRecovery
{
    /** @param array<string, mixed> $kit
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    public function run(#[\SensitiveParameter] array $kit, array $descriptor, ?string $localArtifact = null): array
    {
        foreach (['endpoint', 'bucket', 'prefix', 'operation', 'target', 'root', 'key_id', 'key_file', 'release_id', 'client_binary'] as $key) {
            if (! is_string($kit[$key] ?? null) || $kit[$key] === '') {
                throw new BackupFailure('restore_disabled');
            }
        }
        if (! R2Storage::validEndpoint($kit['endpoint']) || ! preg_match('/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/D', $kit['bucket'])
            || ! is_array($kit['database'] ?? null) || ($localArtifact === null && (! is_string($kit['access_key_id'] ?? null) || ! is_string($kit['secret_access_key'] ?? null)))) {
            throw new BackupFailure('restore_identity_mismatch');
        }
        BackupWorkspace::assertRelative($kit['prefix']);
        BackupWorkspace::assertRelative($descriptor['object_key'] ?? '');
        if (($descriptor['bucket'] ?? null) !== $kit['bucket'] || ($descriptor['prefix'] ?? null) !== $kit['prefix']
            || ($descriptor['endpoint'] ?? null) !== $kit['endpoint'] || ($descriptor['identity']['key_id'] ?? null) !== $kit['key_id']) {
            throw new BackupFailure('restore_identity_mismatch');
        }
        $artifact = new RecoveryArtifact($descriptor['identity'] ?? []);
        $expectedKey = 'runs/'.$artifact->identity['run_uuid'].'/attempt-'.$artifact->identity['attempt_uuid'].'.fhbk';
        if ($descriptor['object_key'] !== $expectedKey) {
            throw new BackupFailure('restore_identity_mismatch');
        }
        if ($localArtifact !== null) {
            if (! preg_match('~\A(?:[a-zA-Z]:[\\\\/]|/)~', $localArtifact) || ! is_file($localArtifact) || is_link($localArtifact)
                || filesize($localArtifact) !== $artifact->identity['size_bytes']) {
                throw new BackupFailure('restore_identity_mismatch');
            }
            app(PrivateFiles::class)->assertPrivate($localArtifact);
        }
        config(['backup-restore.restore.enabled' => true, 'backup-restore.restore.root' => $kit['root'],
            'backup-restore.restore.client_binary' => $kit['client_binary'], 'backup-restore.release_id' => $kit['release_id'],
            'backup-restore.key_files' => [$kit['key_id'] => $kit['key_file']], 'app.key' => $kit['application_key'] ?? '']);
        $owner = bin2hex(random_bytes(32));
        $workspace = RecoveryWorkspace::create($kit['operation'], $owner);
        app(RecoveryDatabase::class)->assertTarget($kit['target'], $workspace);
        $lockPath = dirname($workspace->files->path).'/standalone.lock';
        if (! file_exists($lockPath)) {
            fclose(app(PrivateFiles::class)->create($lockPath));
        }
        app(PrivateFiles::class)->assertPrivate($lockPath);
        $lock = fopen($lockPath, 'r+b');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new BackupFailure('restore_busy');
        }
        try {
            if ($localArtifact !== null) {
                return app(RecoveryEngine::class)->restore($artifact, $workspace, $kit['target'], $owner, $kit['database'],
                    function (string $path, \Closure $tick) use ($localArtifact, $artifact): void {
                        $input = @fopen($localArtifact, 'rb');
                        if ($input === false) {
                            throw new BackupFailure('readback_failed');
                        }
                        $output = null;
                        try {
                            $output = app(PrivateFiles::class)->create($path);
                            $copied = 0;
                            while (! feof($input)) {
                                $chunk = fread($input, 1024 * 1024);
                                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                                    throw new BackupFailure('readback_failed');
                                }
                                $copied += strlen($chunk);
                                if ($copied > $artifact->identity['size_bytes']) {
                                    throw new BackupFailure('checksum_mismatch');
                                }
                                PrivateFiles::write($output, $chunk);
                                $tick();
                            }
                        } finally {
                            fclose($input);
                            if (is_resource($output)) {
                                fclose($output);
                            }
                        }
                    }, static fn () => null);
            }
            $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => $kit['endpoint'],
                'credentials' => ['key' => $kit['access_key_id'], 'secret' => $kit['secret_access_key']], 'use_path_style_endpoint' => false,
                'retries' => 0, 'debug' => false, 'http' => ['connect_timeout' => 3, 'timeout' => 8, 'allow_redirects' => false],
                'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required']);

            return app(RecoveryEngine::class)->restore($artifact, $workspace, $kit['target'], $owner, $kit['database'],
                fn (string $path, \Closure $tick) => app(BackupTransport::class)->downloadObject($client, $kit['bucket'], $kit['prefix'].'/'.$descriptor['object_key'],
                    $artifact->identity['size_bytes'], $artifact->identity['ciphertext_checksum'], $path, $tick), static fn () => null);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
