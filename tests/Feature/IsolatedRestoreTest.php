<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\RestoreCenter;
use App\Modules\BackupRestore\Jobs\VerifyIsolatedRestore;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupArchive;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupLeaseManager;
use App\Modules\BackupRestore\Services\BackupTransport;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\IsolatedRestoreCoordinator;
use App\Modules\BackupRestore\Services\PrivateFiles;
use App\Modules\BackupRestore\Services\RecoveryArtifact;
use App\Modules\BackupRestore\Services\RecoveryDatabase;
use App\Modules\BackupRestore\Services\RecoveryEngine;
use App\Modules\BackupRestore\Services\RecoverySql;
use App\Modules\BackupRestore\Services\RecoveryWorkspace;
use App\Modules\BackupRestore\Services\RestoreVerifier;
use App\Modules\BackupRestore\Services\StandaloneRecovery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class IsolatedRestoreTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private PDO $admin;

    private string $source;

    private array $settings;

    private array $workspaces = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('33316', (string) config('database.connections.mysql.port'), 'No application-server fallback is permitted.');
        $this->setUpBackupFiles();
        $this->settings = ['host' => '127.0.0.1', 'port' => 33316, 'username' => 'root', 'password' => ''];
        config(['backup-restore.restore.enabled' => true, 'backup-restore.restore.root' => $this->backupTestRoot.'/recovery',
            'backup-restore.restore.client_binary' => 'C:\\xampp\\mysql\\bin\\mysql.exe', 'backup-restore.restore.database' => $this->settings]);
        $this->admin = app(RecoveryDatabase::class)->administrator($this->settings);
        $this->source = 'codex_public_ids_test_restore_'.bin2hex(random_bytes(8));
        $this->admin->exec('CREATE DATABASE '.$this->source.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->admin->exec('USE '.$this->source);
        $this->admin->exec('CREATE TABLE brands (id bigint unsigned NOT NULL PRIMARY KEY, icon varchar(100) DEFAULT NULL) ENGINE=InnoDB');
        $this->admin->exec('CREATE TABLE product_images (id bigint unsigned NOT NULL PRIMARY KEY, disk varchar(20) NOT NULL, path varchar(100) NOT NULL) ENGINE=InnoDB');
        $this->admin->exec('CREATE TABLE widgets (id bigint unsigned NOT NULL PRIMARY KEY, brand_id bigint unsigned NOT NULL, CONSTRAINT widget_brand FOREIGN KEY (brand_id) REFERENCES brands(id), CONSTRAINT widget_positive CHECK (id >= 1 AND brand_id IN (1,2))) ENGINE=InnoDB');
        $this->admin->exec("INSERT INTO brands VALUES (1,'brand.png')");
        $this->admin->exec("INSERT INTO product_images VALUES (1,'public','brand.png')");
        $this->admin->exec('INSERT INTO widgets VALUES (1,1)');
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workspaces as [$workspace, $owner]) {
                $target = 'fh_recovery_'.$workspace->operation;
                $query = $this->admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
                $query->execute([$target]);
                if ((int) $query->fetchColumn() !== 0) {
                    app(RecoveryDatabase::class)->cleanup($this->settings, $target, $workspace, $owner);
                }
            }
            if (isset($this->source)) {
                $this->assertStringStartsWith('codex_public_ids_test_restore_', $this->source);
                $this->admin->exec('DROP DATABASE '.$this->source);
            }
            if (isset($this->backupTestRoot)) {
                $this->removeBackupTestDirectory($this->backupTestRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{RecoveryArtifact, string} */
    private function artifact(?string $sql = null, ?\Closure $mutate = null): array
    {
        $dump = '';
        foreach (['brands', 'product_images', 'widgets'] as $table) {
            $dump .= $this->admin->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1].";\n";
        }
        $dump .= "INSERT INTO brands VALUES (1,'brand.png');\nINSERT INTO product_images VALUES (1,'public','brand.png');\nINSERT INTO widgets VALUES (1,1);";
        $dump = preg_replace('/INSERT INTO (brands|product_images|widgets)/', 'INSERT INTO '.chr(96).'$1'.chr(96), $dump);
        $run = (string) Str::uuid();
        $attempt = (string) Str::uuid();
        $workspace = BackupWorkspace::forLease(new BackupLease($run, bin2hex(random_bytes(32)), 1));
        $source = $workspace->file('source.sql');
        file_put_contents($source, $sql ?? $dump);
        $references = $sql === null ? app(RecoverySql::class)->mediaReferences($source, $workspace->file('reference-check.sql'), static fn () => null) : [];
        $image = $workspace->file('image.png');
        file_put_contents($image, 'synthetic-private-media');
        $entry = static fn (string $path, string $file): array => ['path' => $path, 'source' => $file, 'size' => filesize($file), 'sha256' => hash_file('sha256', $file), 'mtime' => 1, 'mode' => 0600];
        $checksum = app(RestoreVerifier::class)->schemaChecksum($this->admin);
        $archive = app(BackupArchive::class)->build($workspace, [$entry('database/database.sql', $source), $entry('files/public/brand.png', $image)],
            ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'release_id' => config('backup-restore.release_id'),
                'composer_lock_checksum' => hash_file('sha256', base_path('composer.lock')), 'consistency_mode' => 'isolated_test',
                'database' => ['database' => $this->source, 'schema_checksum' => $checksum, 'migrations' => [], 'media_references' => $references]], static fn () => null);
        if ($mutate !== null) {
            $bytes = file_get_contents($archive['path']);
            $length = unpack('Nsize', substr($bytes, 6, 4))['size'];
            $manifest = json_decode(substr($bytes, 10, $length), true, flags: JSON_THROW_ON_ERROR);
            $payload = substr($bytes, 10 + $length);
            $mutate($manifest, $payload);
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            file_put_contents($archive['path'], "FHAR1\n".pack('N', strlen($json)).$json.$payload);
            $archive['manifest_checksum'] = hash('sha256', $json);
        }
        $encrypted = $workspace->file('fixture.fhbk');
        app(BackupEncryption::class)->encrypt($archive['path'], $encrypted, 'v1', ['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'manifest_checksum' => $archive['manifest_checksum']], static fn () => null);

        return [new RecoveryArtifact(['run_uuid' => $run, 'attempt_uuid' => $attempt, 'fence' => 1, 'key_id' => 'v1',
            'release_id' => config('backup-restore.release_id'), 'source_database' => $this->source, 'schema_checksum' => $checksum,
            'manifest_checksum' => $archive['manifest_checksum'], 'ciphertext_checksum' => hash_file('sha256', $encrypted), 'size_bytes' => filesize($encrypted), 'file_count' => 2]), $encrypted];
    }

    private function workspace(): array
    {
        $owner = bin2hex(random_bytes(32));
        $workspace = RecoveryWorkspace::create(bin2hex(random_bytes(16)), $owner);
        $this->workspaces[] = [$workspace, $owner];

        return [$workspace, $owner];
    }

    private function restore(RecoveryArtifact $artifact, string $encrypted, ?\Closure $tick = null): array
    {
        [$workspace, $owner] = $this->workspace();

        return app(RecoveryEngine::class)->restore($artifact, $workspace, 'fh_recovery_'.$workspace->operation, $owner, $this->settings,
            static function (string $destination) use ($encrypted): void {
                $stream = app(PrivateFiles::class)->create($destination);
                PrivateFiles::write($stream, file_get_contents($encrypted));
                fclose($stream);
            }, $tick ?? static fn () => null);
    }

    public function test_authenticated_database_files_indexes_foreign_keys_and_media_restore(): void
    {
        [$artifact, $encrypted] = $this->artifact();
        $this->admin->exec("UPDATE brands SET icon='replacement.png'");
        $this->admin->exec("UPDATE product_images SET path='replacement.png'");
        $result = $this->restore($artifact, $encrypted);
        $this->assertTrue($result['isolated_restoration_verified']);
        $this->assertFalse($result['production_recovery_ready']);
        $this->assertSame(3, $result['rows_verified']);
        $this->assertSame(1, $result['foreign_keys_verified']);
        $this->assertSame(1, $result['files_verified']);
        $this->assertSame('synthetic-private-media', file_get_contents($this->workspaces[0][0]->files->file('recovered/files/public/brand.png')));
        $target = 'fh_recovery_'.$this->workspaces[0][0]->operation;
        $this->assertSame('brand.png', $this->admin->query('SELECT icon FROM '.$target.'.brands')->fetchColumn());
        $this->assertSame('brand.png', $this->admin->query('SELECT path FROM '.$target.'.product_images')->fetchColumn());
        $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM widgets')->fetchColumn());
    }

    public function test_restore_schema_fingerprint_matches_the_backup_dump_fingerprint(): void
    {
        $columns = $this->admin->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll(PDO::FETCH_OBJ);
        $indexes = $this->admin->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_OBJ);
        $checks = $this->admin->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_OBJ);
        $references = $this->admin->query('SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_OBJ);
        $expected = hash('sha256', json_encode([$columns, $indexes, $checks, $references], JSON_THROW_ON_ERROR));

        $this->assertSame($expected, app(RestoreVerifier::class)->schemaChecksum($this->admin));
    }

    #[DataProvider('failures')]
    public function test_authentication_identity_and_interruption_fail_closed(string $mode): void
    {
        [$artifact, $encrypted] = $this->artifact();
        if ($mode === 'wrong_key') {
            file_put_contents($this->backupKeyPath, base64_encode(random_bytes(32)));
        } elseif ($mode === 'identity') {
            $identity = $artifact->identity;
            $identity['run_uuid'] = (string) Str::uuid();
            $artifact = new RecoveryArtifact($identity);
        } elseif ($mode === 'truncated' || $mode === 'modified') {
            $bytes = file_get_contents($encrypted);
            $bytes = $mode === 'truncated' ? substr($bytes, 0, -10) : substr_replace($bytes, chr(ord($bytes[500]) ^ 1), 500, 1);
            file_put_contents($encrypted, $bytes);
            $identity = $artifact->identity;
            $identity['ciphertext_checksum'] = hash_file('sha256', $encrypted);
            $identity['size_bytes'] = filesize($encrypted);
            $artifact = new RecoveryArtifact($identity);
        }
        try {
            $this->restore($artifact, $encrypted, $mode === 'interrupted' ? static fn () => throw new BackupFailure('restore_interrupted') : null);
            $this->fail('Unsafe restore accepted');
        } catch (BackupFailure $failure) {
            $this->assertContains($failure->getMessage(), ['authentication_failed', 'archive_invalid', 'restore_identity_mismatch', 'restore_interrupted']);
            $this->assertNull($failure->getPrevious());
            $this->assertFileDoesNotExist($this->workspaces[0][0]->files->path.'/archive.plain');
            $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM widgets')->fetchColumn());
        }
    }

    public static function failures(): array
    {
        return [['wrong_key'], ['identity'], ['truncated'], ['modified'], ['interrupted']];
    }

    #[DataProvider('invalidEntries')]
    public function test_authenticated_malformed_archive_entries_never_pass_recovery(string $mode): void
    {
        [$artifact, $encrypted] = $this->artifact(null, static function (array &$manifest, string &$payload) use ($mode): void {
            if ($mode === 'duplicate') {
                $manifest['files'][] = $manifest['files'][1];
            } elseif ($mode === 'traversal') {
                $manifest['files'][1]['path'] = 'files/public/../../outside';
            } elseif ($mode === 'absolute') {
                $manifest['files'][1]['path'] = 'C:/outside';
            } elseif ($mode === 'symlink_mode') {
                $manifest['files'][1]['mode'] = 0120777;
            } elseif ($mode === 'symlink_type') {
                $manifest['files'][1]['type'] = 'symlink';
            } elseif ($mode === 'missing_media') {
                $manifest['files'][1]['path'] = 'files/public/different.png';
            } elseif ($mode === 'missing_file') {
                $payload = substr($payload, 0, -15);
            } else {
                $offset = $manifest['files'][0]['size'];
                $payload[$offset] = chr(ord($payload[$offset]) ^ 1);
            }
        });
        try {
            $this->restore($artifact, $encrypted);
            $this->fail('Unsafe archive accepted');
        } catch (BackupFailure $failure) {
            $this->assertContains($failure->getMessage(), ['archive_invalid', 'unsafe_path', 'checksum_mismatch', 'missing_media']);
            $this->assertNull($failure->getPrevious());
        }
    }

    public static function invalidEntries(): array
    {
        return array_map(static fn (string $mode): array => [$mode], ['duplicate', 'traversal', 'absolute', 'symlink_mode', 'symlink_type', 'missing_file', 'corrupted_file', 'missing_media']);
    }

    public function test_global_execution_lock_prevents_a_second_restore(): void
    {
        [$artifact, $encrypted] = $this->artifact();
        app(PrivateFiles::class)->directory(config('backup-restore.restore.root'));
        $lock = app(PrivateFiles::class)->create(config('backup-restore.restore.root').'/recovery.lock');
        flock($lock, LOCK_EX);
        try {
            $this->expectExceptionMessage('restore_busy');
            $this->restore($artifact, $encrypted);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_interruption_after_import_preserves_owned_target_and_requires_new_operation(): void
    {
        [$artifact, $encrypted] = $this->artifact();
        try {
            $this->restore($artifact, $encrypted, function (): void {
                if ($this->workspaces !== []) {
                    $journal = $this->workspaces[0][0]->files->path.'/journal.jsonl';
                    if (is_file($journal) && str_contains(file_get_contents($journal), 'database_imported')) {
                        throw new BackupFailure('restore_interrupted');
                    }
                }
            });
            $this->fail('Interruption was ignored');
        } catch (BackupFailure $failure) {
            $this->assertSame('restore_interrupted', $failure->getMessage());
            [$workspace] = $this->workspaces[0];
            $query = $this->admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
            $query->execute(['fh_recovery_'.$workspace->operation]);
            $this->assertSame(1, (int) $query->fetchColumn());
            $this->assertFileDoesNotExist($workspace->files->path.'/client.cnf');
        }
    }

    public function test_unsafe_sql_is_rejected_before_database_creation(): void
    {
        [$artifact, $encrypted] = $this->artifact('USE fox_hema_pos;');
        $this->expectExceptionMessage('restore_sql_unsupported');
        $this->restore($artifact, $encrypted);
    }

    public function test_owned_cleanup_refuses_wrong_owner_and_preserves_recovery_database(): void
    {
        [$artifact, $encrypted] = $this->artifact();
        $this->restore($artifact, $encrypted);
        [$workspace] = $this->workspaces[0];
        try {
            app(RecoveryDatabase::class)->cleanup($this->settings, 'fh_recovery_'.$workspace->operation, $workspace, str_repeat('0', 64));
            $this->fail('Wrong owner accepted');
        } catch (BackupFailure $failure) {
            $this->assertSame('restore_cleanup_unverified', $failure->getMessage());
        }
    }

    public function test_restricted_importer_cannot_write_to_another_disposable_database_even_if_parser_is_bypassed(): void
    {
        [$workspace, $owner] = $this->workspace();
        $stream = app(PrivateFiles::class)->create($workspace->files->file('import.sql'));
        PrivateFiles::write($stream, 'INSERT INTO '.$this->source.".brands VALUES (2,'forbidden.png');");
        fclose($stream);
        $global = app(PrivateFiles::class)->create(config('backup-restore.restore.root').'/recovery.lock');
        fclose($global);
        try {
            app(RecoveryDatabase::class)->import($this->settings, 'fh_recovery_'.$workspace->operation, $workspace, $owner, static fn () => null);
            $this->fail('Importer escaped its target');
        } catch (BackupFailure $failure) {
            $this->assertSame('restore_failed', $failure->getMessage());
            $this->assertSame(1, (int) $this->admin->query('SELECT COUNT(*) FROM brands')->fetchColumn());
            $this->assertFileDoesNotExist($workspace->files->path.'/client.cnf');
        }
    }

    #[DataProvider('forbiddenTargets')]
    public function test_application_database_targets_are_rejected_without_access(string $target): void
    {
        [$workspace] = $this->workspace();
        $this->expectExceptionMessage('restore_target_unsafe');
        app(RecoveryDatabase::class)->assertTarget($target, $workspace);
    }

    public static function forbiddenTargets(): array
    {
        return [['fox_hema_pos'], ['e_commerce'], ['mysql'], ['fh_recovery_existing']];
    }

    public function test_workspace_reuse_and_untrusted_paths_are_rejected(): void
    {
        [$workspace, $owner] = $this->workspace();
        $this->expectExceptionMessage('restore_busy');
        RecoveryWorkspace::create($workspace->operation, $owner);
    }

    private function actor(): User
    {
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $actor = $this->provisionTestSuperAdmin();
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    private function backup(): BackupRun
    {
        [$artifact] = $this->artifact();
        $identity = $artifact->identity;
        $destination = BackupDestination::factory()->create(['probe_status' => 'passed']);

        return BackupRun::factory()->create(['uuid' => $identity['run_uuid'], 'destination_id' => $destination->id, 'status' => 'available',
            'verification_status' => 'checksum_verified', 'completed_at' => now(), 'verified_at' => now(), 'object_key' => 'runs/'.$identity['run_uuid'].'/attempt-'.$identity['attempt_uuid'].'.fhbk',
            'size_bytes' => $identity['size_bytes'], 'ciphertext_checksum' => $identity['ciphertext_checksum'], 'manifest_checksum' => $identity['manifest_checksum'],
            'schema_checksum' => $identity['schema_checksum'], 'file_count' => 2, 'application_version' => $identity['release_id'], 'encryption_key_id' => 'v1',
            'destination_snapshot' => ['endpoint' => $destination->endpoint, 'bucket' => $destination->bucket, 'prefix' => $destination->prefix,
                'database_name' => $this->source, 'attempt_uuid' => $identity['attempt_uuid'], 'fence' => 1]]);
    }

    public function test_restore_requests_are_idempotent_and_concurrent_requests_are_blocked(): void
    {
        $actor = $this->actor();
        Queue::fake();
        $backup = $this->backup();
        $request = (string) Str::uuid();
        $service = app(IsolatedRestoreCoordinator::class);
        $first = $service->request($actor, $backup->uuid, $request);
        $this->assertSame($first->id, $service->request($actor, $backup->uuid, $request)->id);
        Queue::assertPushed(VerifyIsolatedRestore::class, 1);
        $this->expectExceptionMessage('lease_busy');
        $service->request($actor, $backup->uuid, (string) Str::uuid());
    }

    public function test_worker_preflight_failure_is_terminal_audited_and_releases_its_reservation(): void
    {
        $actor = $this->actor();
        Queue::fake();
        $backup = $this->backup();
        $service = app(IsolatedRestoreCoordinator::class);
        $run = $service->request($actor, $backup->uuid, (string) Str::uuid());
        config(['backup-restore.restore.enabled' => false]);
        $engine = Mockery::mock(RecoveryEngine::class);
        $engine->shouldNotReceive('restore');
        app()->instance(RecoveryEngine::class, $engine);

        try {
            $service->execute($run->uuid);
            $this->fail('Disabled restoration was executed.');
        } catch (BackupFailure $failure) {
            $this->assertSame('restore_disabled', $failure->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('restore_disabled', $run->fresh()->failure_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertFalse(BackupOperationLease::findOrFail('global')->expires_at->isFuture());
        $this->assertDatabaseHas('auth_events', ['event' => 'backups.restore_failed']);
        $this->assertSame('available', $backup->fresh()->status);
        $this->assertDirectoryDoesNotExist(config('backup-restore.restore.root').'/'.str_replace('-', '', $run->uuid));

        $service->execute($run->uuid);
        $this->assertSame(1, DB::table('auth_events')->where('event', 'backups.restore_failed')->count());
        config(['backup-restore.restore.enabled' => true]);
        $next = $service->request($actor, $backup->uuid, (string) Str::uuid());
        $this->assertNotSame($run->id, $next->id);
    }

    public static function revokedRestoreRequesters(): array
    {
        return ['permission revoked' => ['permission'], 'disabled' => ['disabled'], 'deleted' => ['deleted']];
    }

    #[DataProvider('revokedRestoreRequesters')]
    public function test_worker_rechecks_requester_before_restore_and_records_permission_failure(string $change): void
    {
        $this->actor();
        Queue::fake();
        setPermissionsTeamId(null);
        $requester = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $requester->givePermissionTo(['backups.restore.request', 'backups.verify']);
        $backup = $this->backup();
        $service = app(IsolatedRestoreCoordinator::class);
        $run = $service->request($requester, $backup->uuid, (string) Str::uuid());
        if ($change === 'permission') {
            $requester->revokePermissionTo('backups.verify');
        } elseif ($change === 'disabled') {
            $requester->update(['is_active' => false]);
        } else {
            $requester->delete();
        }
        $engine = Mockery::mock(RecoveryEngine::class);
        $engine->shouldNotReceive('restore');
        app()->instance(RecoveryEngine::class, $engine);

        try {
            (new VerifyIsolatedRestore($run->uuid))->handle($service);
            $this->fail('Unauthorized restoration was executed.');
        } catch (BackupFailure $failure) {
            $this->assertSame('permission_revoked', $failure->getMessage());
        }
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('permission_revoked', $run->fresh()->failure_code);
        $this->assertNotNull($run->fresh()->completed_at);
        $this->assertFalse(BackupOperationLease::findOrFail('global')->expires_at->isFuture());
        $this->assertDatabaseHas('auth_events', ['event' => 'backups.restore_failed']);
        $this->assertSame('available', $backup->fresh()->status);
        $this->assertDirectoryDoesNotExist(config('backup-restore.restore.root').'/'.str_replace('-', '', $run->uuid));
    }

    public function test_arabic_english_ui_disabled_gate_and_unauthorized_backend(): void
    {
        $this->actor();
        config(['backup-restore.restore.enabled' => false]);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(RestoreCenter::class)->assertOk()->assertSee(__('backup_restore.restore_center'))->assertSee(__('backup_restore.recovery.disabled'))
                ->call('requestVerification')->assertSet('failure', 'restore_disabled');
        }
        $denied = User::factory()->create();
        $this->actingAs($denied);
        Livewire::test(RestoreCenter::class)->assertForbidden();
        $this->expectException(HttpException::class);
        app(IsolatedRestoreCoordinator::class)->request($denied, (string) Str::uuid(), (string) Str::uuid());
    }

    public function test_backend_worker_records_verified_lifecycle_and_audits_without_secrets(): void
    {
        $actor = $this->actor();
        Queue::fake();
        $backup = $this->backup();
        [$artifact, $encrypted] = $this->artifact();
        $identity = $artifact->identity;
        $backup->update(['uuid' => $identity['run_uuid'], 'object_key' => 'runs/'.$identity['run_uuid'].'/attempt-'.$identity['attempt_uuid'].'.fhbk',
            'ciphertext_checksum' => $identity['ciphertext_checksum'], 'manifest_checksum' => $identity['manifest_checksum'],
            'size_bytes' => $identity['size_bytes'], 'destination_snapshot' => array_merge($backup->destination_snapshot, ['attempt_uuid' => $identity['attempt_uuid']])]);
        $transport = Mockery::mock(BackupTransport::class);
        $transport->shouldReceive('download')->once()->andReturnUsing(static function ($run, $path) use ($encrypted): void {
            $stream = app(PrivateFiles::class)->create($path);
            PrivateFiles::write($stream, file_get_contents($encrypted));
            fclose($stream);
        });
        app()->instance(BackupTransport::class, $transport);
        $service = app(IsolatedRestoreCoordinator::class);
        $run = $service->request($actor, $backup->uuid, (string) Str::uuid());
        try {
            $service->execute($run->uuid);
        } finally {
            $path = config('backup-restore.restore.root').'/'.str_replace('-', '', $run->uuid);
            if (is_file($path.'/owner.json')) {
                $this->workspaces[] = [new RecoveryWorkspace(new BackupWorkspace($path), str_replace('-', '', $run->uuid), $run->owner_token), $run->owner_token];
            }
        }
        $this->assertSame('verified', $run->fresh()->status);
        $findings = $run->fresh()->verification_findings;
        $this->assertSame(3, $findings['tables_verified']);
        $this->assertSame(3, $findings['rows_verified']);
        $this->assertSame(1, $findings['files_verified']);
        $this->assertSame(1, $findings['foreign_keys_verified']);
        $recoveryWorkspace = config('backup-restore.restore.root').'/'.str_replace('-', '', $run->uuid);
        $this->assertDirectoryDoesNotExist($recoveryWorkspace);
        $targetQuery = $this->admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $targetQuery->execute([$run->target_identifier]);
        $this->assertSame(0, (int) $targetQuery->fetchColumn());
        Livewire::test(RestoreCenter::class)
            ->assertSee(__('backup_restore.recovery.metrics.tables_verified'))
            ->assertSee(__('backup_restore.recovery.metrics.rows_verified'));
        app(BackupLeaseManager::class)->assertCompatibleOperation(BackupOperationLease::query()->find('global'));
        $this->assertSame(1, $run->fresh()->attempts);
        $service->execute($run->uuid);
        $this->assertSame(1, $run->fresh()->attempts);
        $audit = json_encode(DB::table('auth_events')->get(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('synthetic-private-media', $audit);
        $this->assertStringNotContainsString($run->owner_token, $audit);
    }

    public function test_stale_durable_requests_are_quarantined_without_lease_takeover(): void
    {
        $actor = $this->actor();
        Queue::fake();
        $backup = $this->backup();
        $service = app(IsolatedRestoreCoordinator::class);
        $run = $service->request($actor, $backup->uuid, (string) Str::uuid());
        $run->update(['heartbeat_at' => now()->subHour()]);
        $this->assertSame(1, $service->recoverInterrupted($actor));
        $this->assertSame('restore_interrupted', $run->fresh()->failure_code);
        $this->assertSame(1, DB::table('backup_operation_leases')->where('operation_uuid', $run->uuid)->count());
    }

    public function test_queue_dispatch_failure_is_durable_and_sanitized(): void
    {
        $actor = $this->actor();
        $backup = $this->backup();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('do-not-print-queue-password'));
        try {
            app(IsolatedRestoreCoordinator::class)->request($actor, $backup->uuid, (string) Str::uuid());
            $this->fail('Dispatch failure ignored');
        } catch (BackupFailure $failure) {
            $this->assertSame('queue_dispatch_failed', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
            $this->assertSame('failed', DB::table('backup_restore_runs')->value('status'));
            $this->assertSame('queue_dispatch_failed', DB::table('backup_restore_runs')->value('failure_code'));
            $lease = BackupOperationLease::query()->findOrFail('global');
            $this->assertFalse($lease->expires_at->isFuture());
            app(BackupLeaseManager::class)->assertCompatibleOperation($lease);
        }
    }

    public function test_unprivileged_provisioning_account_is_rejected_before_target_creation(): void
    {
        $username = 'fhr_test_'.bin2hex(random_bytes(6));
        $account = $this->admin->quote($username)."@'127.0.0.1'";
        $this->admin->exec('CREATE USER '.$account." IDENTIFIED BY 'synthetic-only'");
        try {
            $this->expectExceptionMessage('restore_target_unsafe');
            app(RecoveryDatabase::class)->administrator(array_merge($this->settings, ['username' => $username, 'password' => 'synthetic-only']));
        } finally {
            $this->admin->exec('DROP USER '.$account);
        }
    }

    public function test_standalone_recovery_works_without_original_application_database(): void
    {
        [$artifact, $encrypted] = $this->artifact();
        $transport = Mockery::mock(BackupTransport::class);
        $transport->shouldReceive('downloadObject')->once()->andReturnUsing(static function ($client, $bucket, $key, $size, $checksum, $path) use ($encrypted): void {
            $stream = app(PrivateFiles::class)->create($path);
            PrivateFiles::write($stream, file_get_contents($encrypted));
            fclose($stream);
        });
        app()->instance(BackupTransport::class, $transport);
        $operation = bin2hex(random_bytes(16));
        $kit = ['endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'synthetic-recovery', 'prefix' => 'erp-backups',
            'operation' => $operation, 'target' => 'fh_recovery_'.$operation, 'root' => config('backup-restore.restore.root'),
            'key_id' => 'v1', 'key_file' => $this->backupKeyPath, 'release_id' => config('backup-restore.release_id'),
            'client_binary' => config('backup-restore.restore.client_binary'), 'database' => $this->settings, 'access_key_id' => 'synthetic', 'secret_access_key' => 'synthetic',
            'application_key' => config('app.key')];
        $descriptor = ['endpoint' => $kit['endpoint'], 'bucket' => $kit['bucket'], 'prefix' => $kit['prefix'],
            'object_key' => 'runs/'.$artifact->identity['run_uuid'].'/attempt-'.$artifact->identity['attempt_uuid'].'.fhbk', 'identity' => $artifact->identity];
        $default = config('database.default');
        config(['database.default' => 'original_database_unavailable']);
        try {
            $result = app(StandaloneRecovery::class)->run($kit, $descriptor);
            $this->assertTrue($result['isolated_restoration_verified']);
        } finally {
            config(['database.default' => $default]);
            $path = $kit['root'].'/'.$operation;
            if (is_file($path.'/owner.json')) {
                $owner = json_decode(file_get_contents($path.'/owner.json'), true)['owner'];
                $this->workspaces[] = [new RecoveryWorkspace(new BackupWorkspace($path), $operation, $owner), $owner];
            }
        }
    }

    public function test_independent_cli_bootstrap_refuses_application_target_without_database_or_r2_access(): void
    {
        [$artifact] = $this->artifact();
        $operation = bin2hex(random_bytes(16));
        $identity = $artifact->identity;
        $identity['source_database'] = 'fox_hema_pos';
        $kit = ['endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'synthetic-recovery', 'prefix' => 'erp-backups',
            'operation' => $operation, 'target' => 'fox_hema_pos', 'root' => config('backup-restore.restore.root'),
            'key_id' => 'v1', 'key_file' => $this->backupKeyPath, 'release_id' => config('backup-restore.release_id'),
            'client_binary' => config('backup-restore.restore.client_binary'), 'database' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'unavailable', 'password' => 'do-not-print-password'],
            'access_key_id' => 'do-not-print-key', 'secret_access_key' => 'do-not-print-secret'];
        $descriptor = ['endpoint' => $kit['endpoint'], 'bucket' => $kit['bucket'], 'prefix' => $kit['prefix'],
            'object_key' => 'runs/'.$identity['run_uuid'].'/attempt-'.$identity['attempt_uuid'].'.fhbk', 'identity' => $identity];
        foreach (['kit' => $kit, 'descriptor' => $descriptor] as $name => $data) {
            $stream = app(PrivateFiles::class)->create($this->backupTestRoot.'/'.$name.'.json');
            PrivateFiles::write($stream, json_encode($data, JSON_THROW_ON_ERROR));
            fclose($stream);
        }
        $process = new Process([PHP_BINARY, base_path('app/Modules/BackupRestore/Console/recover.php'),
            '--kit='.$this->backupTestRoot.'/kit.json', '--descriptor='.$this->backupTestRoot.'/descriptor.json', '--approve-isolated'], base_path(),
            ['APP_ENV' => 'local', 'DB_DATABASE' => 'original_database_unavailable', 'DB_PORT' => '1']);
        $process->setTimeout(20)->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('restore_target_unsafe', $process->getErrorOutput());
        $this->assertStringNotContainsString('do-not-print', $process->getOutput().$process->getErrorOutput());
    }

    public function test_cli_refuses_an_explicit_production_environment_before_reading_recovery_inputs(): void
    {
        $process = new Process([PHP_BINARY, base_path('app/Modules/BackupRestore/Console/recover.php'),
            '--kit=not-opened.json', '--descriptor=not-opened.json', '--approve-isolated'], base_path(), ['APP_ENV' => 'production']);
        $process->setTimeout(10)->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertSame('Recovery refused: restore_disabled'.PHP_EOL, $process->getErrorOutput());
    }
}
