<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupArchive;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupKeyProvider;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\PrivateFiles;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class BackupArchiveTest extends TestCase
{
    use BackupFileEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackupFiles();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->backupTestRoot)) {
                $this->removeBackupTestDirectory($this->backupTestRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_authenticated_streaming_round_trip_and_complete_file_manifest(): void
    {
        [$workspace, $archive, $metadata] = $this->archive(str_repeat('private-payload-', 160000));
        $encrypted = $workspace->file('artifact.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', ['run_uuid' => $metadata['run_uuid'], 'manifest_checksum' => $archive['manifest_checksum']], fn () => null);
        $bytes = file_get_contents($encrypted);
        $this->assertFalse(str_contains($bytes, 'private-payload-'));
        $this->assertFalse(str_contains($bytes, trim(file_get_contents($this->backupKeyPath))));
        $plain = $workspace->file('verified.plain');
        $header = app(BackupEncryption::class)->decrypt($encrypted, $plain, fn () => null);
        $this->assertSame($metadata['run_uuid'], $header['run_uuid']);
        $this->assertSame(hash_file('sha256', $archive['path']), hash_file('sha256', $plain));
        $verified = app(BackupArchive::class)->verify($plain, fn () => null);
        $this->assertSame($archive['manifest_checksum'], $verified['manifest_checksum']);
        $this->assertCount(1, $verified['manifest']['files']);
        $this->assertSame('database/database.sql', $verified['manifest']['files'][0]['path']);
    }

    public function test_wrong_key_fails_authentication_and_removes_partial_plaintext(): void
    {
        [$workspace, $archive] = $this->archive('private database');
        $encrypted = $workspace->file('artifact.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', [], fn () => null);
        file_put_contents($this->backupKeyPath, base64_encode(random_bytes(32)));
        $plain = $workspace->file('failed.plain');
        try {
            app(BackupEncryption::class)->decrypt($encrypted, $plain, fn () => null);
            $this->fail('A wrong key was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('authentication_failed', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
            $this->assertFileDoesNotExist($plain);
        }
    }

    #[DataProvider('corruptionModes')]
    public function test_corrupted_truncated_or_reframed_ciphertext_never_yields_plaintext(string $mode): void
    {
        [$workspace, $archive] = $this->archive(str_repeat('secret data', 100000));
        $encrypted = $workspace->file('artifact.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', ['test_marker' => 'original'], fn () => null);
        $bytes = file_get_contents($encrypted);
        if ($mode === 'truncate') {
            $bytes = substr($bytes, 0, -8);
        } elseif ($mode === 'append') {
            $bytes .= 'unexpected trailing bytes';
        } elseif ($mode === 'metadata') {
            $bytes = str_replace('original', 'tampered', $bytes);
        } else {
            $offset = strlen($bytes) - 30;
            $bytes[$offset] = chr(ord($bytes[$offset]) ^ 1);
        }
        file_put_contents($encrypted, $bytes);
        $plain = $workspace->file('failed.plain');
        try {
            app(BackupEncryption::class)->decrypt($encrypted, $plain, fn () => null);
            $this->fail('Invalid ciphertext was accepted.');
        } catch (BackupFailure) {
            $this->assertFileDoesNotExist($plain);
        }
    }

    public static function corruptionModes(): array
    {
        return [['truncate'], ['append'], ['metadata'], ['ciphertext']];
    }

    public function test_plain_archive_detects_missing_files_and_checksum_mismatch(): void
    {
        [$workspace, $archive] = $this->archive('database payload');
        $stream = fopen($archive['path'], 'r+b');
        fseek($stream, 6);
        $length = unpack('Nsize', fread($stream, 4))['size'];
        fseek($stream, 10 + $length);
        fwrite($stream, '!');
        fclose($stream);
        try {
            app(BackupArchive::class)->verify($archive['path'], fn () => null);
            $this->fail('A mismatched file checksum was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('checksum_mismatch', $failure->getMessage());
        }
        file_put_contents($archive['path'], substr(file_get_contents($archive['path']), 0, 10 + $length));
        $this->expectException(BackupFailure::class);
        app(BackupArchive::class)->verify($archive['path'], fn () => null);
    }

    #[DataProvider('unsafePaths')]
    public function test_paths_reject_traversal_absolute_paths_and_stream_aliases(string $path): void
    {
        $this->expectException(BackupFailure::class);
        BackupWorkspace::assertRelative($path);
    }

    public static function unsafePaths(): array
    {
        return array_map(fn (string $path): array => [$path], ['../outside', '/absolute', 'a/../b', 'C:/outside', 'a\\b', 'a//b', 'a/%2e%2e/b', 'php://filter', "a\0b", 'a/NUL.txt', 'a/trailing.', 'a/trailing ', 'a/*wildcard']);
    }

    public function test_independent_key_cannot_equal_app_key_or_live_inside_captured_roots(): void
    {
        $key = config('app.key');
        file_put_contents($this->backupKeyPath, str_starts_with($key, 'base64:') ? substr($key, 7) : base64_encode($key));
        try {
            app(BackupKeyProvider::class)->withKey('v1', fn () => null);
            $this->fail('APP_KEY was accepted as a backup key.');
        } catch (BackupFailure $failure) {
            $this->assertSame('key_not_independent', $failure->getMessage());
        }
        $unsafe = $this->backupTestRoot.'/public/key-file';
        $stream = app(PrivateFiles::class)->create($unsafe);
        PrivateFiles::write($stream, base64_encode(random_bytes(32)));
        fclose($stream);
        config(['backup-restore.key_files.v1' => $unsafe]);
        $this->expectException(BackupFailure::class);
        app(BackupKeyProvider::class)->withKey('v1', fn () => null);
    }

    public function test_cleanup_rejects_unowned_paths_and_never_deletes_neighbor_files(): void
    {
        $neighbor = $this->backupTestRoot.'/neighbor';
        mkdir($neighbor);
        file_put_contents($neighbor.'/keep.txt', 'keep');
        try {
            (new BackupWorkspace($neighbor))->cleanup();
            $this->fail('An unowned directory was cleaned.');
        } catch (BackupFailure) {
            $this->assertFileExists($neighbor.'/keep.txt');
        }
    }

    public function test_key_file_with_broad_permissions_is_rejected_without_changing_its_contents(): void
    {
        $before = hash_file('sha256', $this->backupKeyPath);
        if (PHP_OS_FAMILY === 'Windows') {
            $binary = (getenv('SystemRoot') ?: 'C:\Windows').'\System32\icacls.exe';
            $process = new Process([$binary, $this->backupKeyPath, '/grant', '*S-1-1-0:R', '/q']);
            $process->setTimeout(10)->mustRun();
        } else {
            chmod($this->backupKeyPath, 0644);
        }
        try {
            app(BackupKeyProvider::class)->withKey('v1', fn () => null);
            $this->fail('A broadly readable key was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('private_storage_unavailable', $failure->getMessage());
            $this->assertSame($before, hash_file('sha256', $this->backupKeyPath));
        }
    }

    private function archive(string $contents): array
    {
        $uuid = (string) Str::uuid();
        $workspace = BackupWorkspace::forLease(new BackupLease($uuid, bin2hex(random_bytes(32)), 1));
        $source = $workspace->file('database.sql');
        $stream = app(PrivateFiles::class)->create($source);
        PrivateFiles::write($stream, $contents);
        fclose($stream);
        $metadata = ['run_uuid' => $uuid, 'attempt_uuid' => (string) Str::uuid(), 'fence' => 1, 'release_id' => 'test-release',
            'consistency_mode' => 'isolated_test', 'database' => ['schema_checksum' => str_repeat('a', 64), 'migrations' => []]];
        $archive = app(BackupArchive::class)->build($workspace, [['path' => 'database/database.sql', 'source' => $source,
            'size' => strlen($contents), 'sha256' => hash('sha256', $contents), 'mtime' => time(), 'mode' => 0600]], $metadata, fn () => null);

        return [$workspace, $archive, $metadata];
    }
}
