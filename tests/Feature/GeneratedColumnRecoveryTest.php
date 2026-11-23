<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\RecoveryDatabase;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\BackupRestore\Services\RecoveryWorkspace;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BackupFileEnvironment;
use Tests\TestCase;

class GeneratedColumnRecoveryTest extends TestCase
{
    use BackupFileEnvironment;

    #[DataProvider('nativeValues')]
    public function test_strict_import_recomputes_and_verifies_native_generated_literals(string $mode): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $this->setUpBackupFiles();
        config(['backup-restore.restore.root' => $this->backupTestRoot.'/recovery', 'backup-restore.restore.client_binary' => 'C:/xampp/mysql/bin/mysql.exe']);
        $operation = bin2hex(random_bytes(16));
        $owner = bin2hex(random_bytes(32));
        $workspace = RecoveryWorkspace::create($operation, $owner);
        fclose(app(PrivateFiles::class)->create(dirname($workspace->files->path).'/recovery.lock'));
        $target = 'fh_recovery_'.$operation;
        $settings = ['host' => '127.0.0.1', 'port' => 33316, 'username' => 'root', 'password' => ''];
        $created = false;
        try {
            $source = $workspace->files->file('native.sql');
            $destination = $workspace->files->file('import.sql');
            $expected = $mode === 'wrong_generated_value' ? '9007199254740992.2469' : '9007199254740992.2468';
            $expectedId = $mode === 'wrong_large_generated_id' ? '9007199254740992' : '9007199254740993';
            $definition = 'CREATE TABLE `samples` (`id` bigint, `quantity` decimal(30,4), `label` varchar(100), `bytes` varbinary(10), `created_at` timestamp, `doubled` decimal(30,4) GENERATED ALWAYS AS ((`quantity` * 2)) STORED, `copied_id` bigint GENERATED ALWAYS AS (`id`) STORED, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';
            $label = "'escaped".chr(92)."'line".chr(92)."n'";
            file_put_contents($source, $definition.'INSERT INTO `samples` VALUES (9007199254740993,4503599627370496.1234,'.$label.',0x0041ff,\'2026-11-14 12:34:56\','.$expected.','.$expectedId.');');
            $sql = app(RecoverySql::class);
            $counts = $sql->prepare($source, $destination, static fn () => null);
            $compiled = file_get_contents($destination);
            $this->assertStringContainsString('`doubled` decimal(30,4) GENERATED ALWAYS AS ((`quantity` * 2)) STORED', $compiled);
            $this->assertStringContainsString('INSERT INTO `samples` (`id`,`quantity`,`label`,`bytes`,`created_at`) VALUES', $compiled);
            $this->assertStringContainsString('STRICT_ALL_TABLES', $compiled);
            $database = app(RecoveryDatabase::class)->import($settings, $target, $workspace, $owner, static fn () => null);
            $created = true;
            $this->assertSame('9007199254740992.2468', $database->query('SELECT doubled FROM samples')->fetchColumn());
            $this->assertSame('9007199254740993', (string) $database->query('SELECT copied_id FROM samples')->fetchColumn());
            $this->assertSame('+00:00', $database->query('SELECT @@session.time_zone')->fetchColumn());
            $this->assertSame((new \DateTimeImmutable('2026-11-14T12:34:56+00:00'))->getTimestamp(), (int) $database->query('SELECT UNIX_TIMESTAMP(created_at) FROM samples')->fetchColumn());
            $checkPath = $destination.'.generated.jsonl';
            if ($mode === 'missing_checks') {
                unlink($checkPath);
            } elseif ($mode === 'truncated_checks') {
                file_put_contents($checkPath, '');
            }
            if (str_starts_with($mode, 'tampered_')) {
                $check = json_decode(file_get_contents($checkPath), true, flags: JSON_THROW_ON_ERROR);
                if ($mode === 'tampered_literal') {
                    $check['values'][0] = 'SLEEP(1)';
                } elseif ($mode === 'tampered_identifier') {
                    $check['columns'][0] = 'id` FROM mysql.user; --';
                } else {
                    $check['table'] = '__fh_recovery_owner';
                }
                file_put_contents($checkPath, json_encode($check, JSON_THROW_ON_ERROR)."\n");
            }
            if ($mode === 'verified') {
                $this->assertSame(1, $sql->verifyGenerated($checkPath, $database, $counts, static fn () => null));
            } else {
                try {
                    $sql->verifyGenerated($checkPath, $database, $counts, static fn () => null);
                    $this->fail('Invalid generated value or sidecar must fail closed.');
                } catch (BackupFailure $failure) {
                    $this->assertContains($failure->getMessage(), ['restore_verification_failed', 'restore_sql_unsupported']);
                }
            }
            $workspace->removeTransient();
            $this->assertFileDoesNotExist($checkPath);
        } finally {
            if ($created) {
                app(RecoveryDatabase::class)->cleanup($settings, $target, $workspace, $owner);
            }
            $this->removeBackupTestDirectory($this->backupTestRoot);
        }
    }

    /** @return list<array{string}> */
    public static function nativeValues(): array
    {
        return [['verified'], ['wrong_generated_value'], ['wrong_large_generated_id'], ['tampered_literal'], ['tampered_identifier'], ['tampered_table'], ['missing_checks'], ['truncated_checks']];
    }
}
