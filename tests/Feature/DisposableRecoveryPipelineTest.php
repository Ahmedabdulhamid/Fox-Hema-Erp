<?php

namespace Tests\Feature;

use App\Modules\BackupRestore\Services\BackupArchive;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\RecoveryDatabase;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\BackupRestore\Services\RecoveryWorkspace;
use App\Modules\BackupRestore\Services\RestoreVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class DisposableRecoveryPipelineTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private PDO $sourceConnection;

    private string $source;

    private array $settings = ['host' => '127.0.0.1', 'port' => 33316, 'username' => 'root', 'password' => ''];

    private ?RecoveryWorkspace $recoveryWorkspace = null;

    private ?string $recoveryOwner = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->setUpBackupFiles();
        Http::preventStrayRequests();
        config(['backup-restore.restore.root' => $this->backupTestRoot.'/recovery']);
        $this->sourceConnection = app(RecoveryDatabase::class)->administrator($this->settings);
        $this->source = 'codex_public_ids_test_pipeline_'.bin2hex(random_bytes(8));
        $this->sourceConnection->exec('CREATE DATABASE '.$this->source.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->sourceConnection->exec('USE '.$this->source);
        foreach ([
            "CREATE TABLE branches (id bigint unsigned PRIMARY KEY, name varchar(80) NOT NULL) ENGINE=InnoDB COMMENT='Synthetic branch ledger'",
            'CREATE TABLE brands (id bigint unsigned PRIMARY KEY, icon varchar(100) DEFAULT NULL) ENGINE=InnoDB',
            'CREATE TABLE product_images (id bigint unsigned PRIMARY KEY, disk varchar(20) NOT NULL, path varchar(100) NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE sales_invoices (id bigint unsigned PRIMARY KEY, branch_id bigint unsigned NOT NULL, amount decimal(18,4) NOT NULL, status varchar(20) NOT NULL, CONSTRAINT invoice_branch FOREIGN KEY (branch_id) REFERENCES branches(id), CONSTRAINT invoice_amount CHECK (amount >= 0)) ENGINE=InnoDB',
            'CREATE TABLE synthetic_ledger_entries (id bigint unsigned PRIMARY KEY, branch_id bigint unsigned NOT NULL, amount decimal(18,4) NOT NULL, CONSTRAINT treasury_branch FOREIGN KEY (branch_id) REFERENCES branches(id)) ENGINE=InnoDB',
            'CREATE TABLE stock_movements (id bigint unsigned PRIMARY KEY, branch_id bigint unsigned NOT NULL, quantity decimal(18,4) NOT NULL, CONSTRAINT stock_branch FOREIGN KEY (branch_id) REFERENCES branches(id)) ENGINE=InnoDB',
            "INSERT INTO branches VALUES (1,'Synthetic branch'),(2,'Synthetic warehouse')",
            "INSERT INTO brands VALUES (1,'brand.png')",
            "INSERT INTO product_images VALUES (1,'public','products/shirt.png')",
            "INSERT INTO sales_invoices VALUES (1,1,123.4567,'posted')",
            'INSERT INTO synthetic_ledger_entries VALUES (1,1,123.4567)',
            'INSERT INTO stock_movements VALUES (1,1,-1.0000)',
        ] as $sql) {
            $this->sourceConnection->exec($sql);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->recoveryWorkspace !== null) {
                $target = 'fh_recovery_'.$this->recoveryWorkspace->operation;
                $exists = $this->sourceConnection->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
                $exists->execute([$target]);
                if ((int) $exists->fetchColumn() > 0) {
                    app(RecoveryDatabase::class)->cleanup($this->settings, $target, $this->recoveryWorkspace, $this->recoveryOwner);
                }
            }
            if (isset($this->source)) {
                $this->assertStringStartsWith('codex_public_ids_test_pipeline_', $this->source);
                $this->sourceConnection->exec('DROP DATABASE '.$this->source);
            }
            if (isset($this->backupTestRoot)) {
                $this->removeBackupTestDirectory($this->backupTestRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('pipelineModes')]
    public function test_disposable_dump_archive_encryption_fake_storage_and_independent_cli_restore(string $mode): void
    {
        $run = (string) Str::uuid();
        $attempt = (string) Str::uuid();
        $workspace = BackupWorkspace::forLease(new BackupLease($run, bin2hex(random_bytes(32)), 1));
        $dump = $workspace->file('synthetic.sql');
        $process = new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33316', '--user=root',
            '--single-transaction', '--skip-lock-tables', '--hex-blob', '--skip-triggers', '--routines=false', '--events=false', '--result-file='.$dump, $this->source]);
        $process->setTimeout(30)->mustRun();
        file_put_contents(storage_path('framework/testing/phase6b/synthetic-dump.sql'), file_get_contents($dump));
        $tables = ['branches', 'brands', 'product_images', 'sales_invoices', 'stock_movements', 'synthetic_ledger_entries'];
        $before = $this->rows($this->sourceConnection, $tables);
        $files = [];
        foreach (['database/database.sql' => $dump, 'files/public/brand.png' => 'synthetic-brand', 'files/public/products/shirt.png' => 'synthetic-shirt'] as $path => $contents) {
            $source = $path === 'database/database.sql' ? $dump : $workspace->file(str_replace('/', '-', $path));
            if ($source !== $dump) {
                file_put_contents($source, $contents);
            }
            $files[] = ['path' => $path, 'source' => $source, 'size' => filesize($source), 'sha256' => hash_file('sha256', $source), 'mtime' => 1, 'mode' => 0600];
        }
        $schemaChecksum = app(RestoreVerifier::class)->schemaChecksum($this->sourceConnection);
        $archive = app(BackupArchive::class)->build($workspace, $files, ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1,
            'release_id' => config('backup-restore.release_id'), 'composer_lock_checksum' => hash_file('sha256', base_path('composer.lock')),
            'consistency_mode' => 'online_transactional', 'database' => ['database' => $this->source, 'schema_checksum' => $schemaChecksum, 'migrations' => []]], static fn () => null);
        $encrypted = $workspace->file('synthetic.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'manifest_checksum' => $archive['manifest_checksum']], static fn () => null);
        $identity = ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'key_id' => 'v1', 'release_id' => config('backup-restore.release_id'),
            'source_database' => $this->source, 'schema_checksum' => $schemaChecksum, 'manifest_checksum' => $archive['manifest_checksum'],
            'ciphertext_checksum' => hash_file('sha256', $encrypted), 'size_bytes' => filesize($encrypted), 'file_count' => count($files)];
        $object = 'runs/'.$run.'/attempt-'.$attempt.'.fhbk';
        $disk = Storage::fake('phase6b-object-store');
        $disk->put('synthetic-namespace/'.$object, file_get_contents($encrypted));
        $downloaded = $workspace->file('offline-artifact.fhbk');
        $stream = app(PrivateFiles::class)->create($downloaded);
        PrivateFiles::write($stream, $disk->get('synthetic-namespace/'.$object));
        fclose($stream);
        $this->assertSame($identity['ciphertext_checksum'], hash_file('sha256', $downloaded));
        $operation = bin2hex(random_bytes(16));
        $kit = ['endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'synthetic-only', 'prefix' => 'synthetic-namespace',
            'operation' => $operation, 'target' => 'fh_recovery_'.$operation, 'root' => config('backup-restore.restore.root'),
            'key_id' => 'v1', 'key_file' => $this->backupKeyPath, 'release_id' => config('backup-restore.release_id'),
            'client_binary' => 'C:/xampp/mysql/bin/mysql.exe', 'database' => $this->settings];
        $descriptor = ['endpoint' => $kit['endpoint'], 'bucket' => $kit['bucket'], 'prefix' => $kit['prefix'], 'object_key' => $object, 'identity' => $identity];
        if ($mode === 'wrong_key') {
            file_put_contents($this->backupKeyPath, base64_encode(random_bytes(32)));
        } elseif ($mode === 'corrupted_ciphertext') {
            $bytes = file_get_contents($downloaded);
            $bytes = substr_replace($bytes, chr(ord($bytes[500]) ^ 1), 500, 1);
            file_put_contents($downloaded, $bytes);
            $descriptor['identity']['ciphertext_checksum'] = hash('sha256', $bytes);
        } elseif ($mode === 'namespace_mismatch') {
            $descriptor['prefix'] = 'different-synthetic-namespace';
        } elseif ($mode === 'truncated_artifact') {
            file_put_contents($downloaded, substr(file_get_contents($downloaded), 0, -10));
        }
        foreach (['kit' => $kit, 'descriptor' => $descriptor] as $name => $contents) {
            $stream = app(PrivateFiles::class)->create($workspace->file($name.'.json'));
            PrivateFiles::write($stream, json_encode($contents, JSON_THROW_ON_ERROR));
            fclose($stream);
        }
        $cli = new Process([PHP_BINARY, base_path('app/Modules/BackupRestore/Console/recover.php'), '--kit='.$workspace->file('kit.json'),
            '--descriptor='.$workspace->file('descriptor.json'), '--artifact='.$downloaded, '--approve-isolated'], base_path(),
            ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'unavailable_original_database', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_DATABASE' => 'unavailable_original_database']);
        try {
            $cli->setTimeout(90)->run();
            if ($mode !== 'verified') {
                $this->assertSame(1, $cli->getExitCode());
                $this->assertMatchesRegularExpression('/Recovery refused: (authentication_failed|archive_invalid|restore_identity_mismatch)/', $cli->getErrorOutput());
                $exists = $this->sourceConnection->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
                $exists->execute([$kit['target']]);
                $this->assertSame(0, (int) $exists->fetchColumn());
                $this->assertSame($before, $this->rows($this->sourceConnection, $tables));

                return;
            }
            $this->assertSame(0, $cli->getExitCode(), $cli->getErrorOutput());
            $result = json_decode($cli->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['archive_integrity_verified']);
            $this->assertTrue($result['isolated_restoration_verified']);
            $this->assertFalse($result['production_recovery_ready']);
            $this->sourceConnection->exec('USE '.$kit['target']);
            $this->assertSame($before, $this->rows($this->sourceConnection, $tables));
            $this->assertSame($schemaChecksum, app(RestoreVerifier::class)->schemaChecksum($this->sourceConnection));
            $this->assertSame('Synthetic branch ledger', $this->sourceConnection->query("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches'")->fetchColumn());
            foreach (array_slice($files, 1) as $file) {
                $recovered = $kit['root'].'/'.$operation.'/recovered/'.$file['path'];
                $this->assertSame($file['sha256'], hash_file('sha256', $recovered));
            }
            file_put_contents(storage_path('framework/testing/phase6b/disposable-recovery.json'), json_encode(['component_pipeline_verified' => true, 'independent_cli_verified' => true,
                'original_application_database_unavailable' => true, 'tables' => count($tables), 'rows' => 7, 'media_files' => 2,
                'schema_and_record_equality' => true, 'real_capture_engine' => 'blocked_not_bypassed', 'real_r2_operations' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            $path = $kit['root'].'/'.$operation;
            if (is_file($path.'/owner.json')) {
                $owner = json_decode(file_get_contents($path.'/owner.json'), true, flags: JSON_THROW_ON_ERROR)['owner'];
                $this->recoveryOwner = $owner;
                $this->recoveryWorkspace = new RecoveryWorkspace(new BackupWorkspace($path), $operation, $owner);
            }
            $disk->deleteDirectory('synthetic-namespace');
        }
    }

    /** @return list<array{string}> */
    public static function pipelineModes(): array
    {
        return [['verified'], ['wrong_key'], ['corrupted_ciphertext'], ['namespace_mismatch'], ['truncated_artifact']];
    }

    public function test_full_disposable_erp_schema_language_probe_reports_unsupported_features_without_importing(): void
    {
        $database = DB::connection()->getDatabaseName();
        $this->assertStringStartsWith('codex_public_ids_test_', $database);
        $source = $this->backupTestRoot.'/full-disposable-schema.sql';
        $process = new Process(['C:/xampp/mysql/bin/mysqldump.exe', '--no-defaults', '--protocol=tcp', '--host=127.0.0.1', '--port=33316', '--user=root',
            '--no-data', '--skip-triggers', '--routines=false', '--events=false', '--result-file='.$source, $database]);
        $process->setTimeout(30)->mustRun();
        $expected = DB::connection()->getSchemaBuilder()->getTableListing($database, false);
        try {
            $counts = app(RecoverySql::class)->prepare($source, $this->backupTestRoot.'/validated-schema.sql', static fn () => null);
            $this->assertCount(count($expected), $counts);
            $result = ['classification' => 'verified_in_disposable_mariadb', 'schema_language_compatible' => true, 'tables' => count($counts), 'actual_data_restore' => 'not_tested_by_this_probe'];
        } catch (BackupFailure $failure) {
            $this->assertSame('restore_sql_unsupported', $failure->getMessage());
            $validated = $this->backupTestRoot.'/validated-schema.sql';
            preg_match_all('/CREATE TABLE `([^`]+)`/', (string) file_get_contents($source), $sourceTables);
            preg_match_all('/CREATE TABLE `([^`]+)`/', is_file($validated) ? (string) file_get_contents($validated) : '', $validatedTables);
            $candidates = array_values(array_diff($sourceTables[1], $validatedTables[1]));
            $result = ['classification' => 'blocked_by_unresolved_safety_risk', 'schema_language_compatible' => false,
                'reason' => $failure->getMessage(), 'tables' => count($expected), 'first_uncompiled_table' => $candidates[0] ?? null,
                'actual_data_restore' => 'not_attempted'];
            file_put_contents(storage_path('framework/testing/phase6b/full-disposable-schema.sql'), file_get_contents($source));
        }
        file_put_contents(storage_path('framework/testing/phase6b/full-schema-language-probe.json'), json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->assertNull($this->recoveryWorkspace);
    }

    /** @param list<string> $tables
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function rows(PDO $database, array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = $database->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }
}
