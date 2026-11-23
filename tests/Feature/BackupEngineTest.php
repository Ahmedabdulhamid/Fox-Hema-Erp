<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\BackupHistory;
use App\Modules\BackupRestore\Filament\Pages\BackupSettings;
use App\Modules\BackupRestore\Jobs\CreateFullBackup;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupOperationLease;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupArchive;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupKeyProvider;
use App\Modules\BackupRestore\Services\BackupLease;
use App\Modules\BackupRestore\Services\BackupLeaseManager;
use App\Modules\BackupRestore\Services\BackupReadiness;
use App\Modules\BackupRestore\Services\BackupWorkspace;
use App\Modules\BackupRestore\Services\CaptureProtection;
use App\Modules\BackupRestore\Services\DatabaseDumper;
use App\Modules\BackupRestore\Services\FileSnapshot;
use App\Modules\BackupRestore\Services\OnlineCaptureProtection;
use App\Modules\BackupRestore\Services\R2Storage;
use App\Modules\BackupRestore\Services\ScheduledBackups;
use App\Modules\BackupRestore\Services\TrustedTreasuryTriggers;
use App\Modules\BackupRestore\Services\UnavailableCaptureProtection;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductImageManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\BackupS3Fake;
use Tests\Support\FakeBackupR2Storage;
use Tests\Support\IsolatedBackupCapture;
use Tests\TestCase;

class BackupEngineTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private User $actor;

    private BackupS3Fake $s3;

    private IsolatedBackupCapture $capture;

    private string $fakeDiskRoot;

    /** @var list<string> */
    private array $schemaLockAccounts = [];

    protected function beforeRefreshingDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('127.0.0.1', $connection->getConfig('host'));
        $this->assertSame(33316, (int) $connection->getConfig('port'));
        $this->assertMatchesRegularExpression('/\Acodex_public_ids_test_treasury_[0-9]+_[a-f0-9]{8}\z/D', $database);
        DB::statement('ALTER DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE '.TrustedTreasuryTriggers::DATABASE_COLLATION);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $this->setUpBackupFiles();
        config(['backup-restore.dump_binary' => 'C:/xampp/mysql/bin/mysqldump.exe',
            'backup-restore.release_id' => app(TrustedTreasuryTriggers::class)->releaseId()]);
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        setPermissionsTeamId(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->capture = new IsolatedBackupCapture(DB::connection()->getDatabaseName(), $this->backupTestRoot);
        app()->instance(CaptureProtection::class, $this->capture);
        $disk = Storage::fake('backup-engine-r2-'.bin2hex(random_bytes(8)));
        $this->fakeDiskRoot = $disk->path('');
        $this->s3 = new BackupS3Fake($disk);
        app()->instance(R2Storage::class, new FakeBackupR2Storage($this->s3));
        BackupDestination::factory()->create(['access_key_id' => 'fixture-access', 'secret_access_key' => 'fixture-secret', 'probe_status' => 'passed']);
        Queue::fake();
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            foreach ($this->schemaLockAccounts as $account) {
                DB::unprepared("DROP USER IF EXISTS '".$account."'@'127.0.0.1'");
            }
            foreach (['backupTestRoot', 'fakeDiskRoot'] as $property) {
                if (isset($this->{$property})) {
                    $this->removeBackupTestDirectory($this->{$property});
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_real_isolated_dump_media_encryption_upload_and_readback_become_available(): void
    {
        $this->assertSame([], app(BackupReadiness::class)->blockers());
        mkdir($this->backupTestRoot.'/public/products');
        mkdir($this->backupTestRoot.'/public/brands');
        file_put_contents($this->backupTestRoot.'/public/products/test.jpg', 'product-image-evidence');
        file_put_contents($this->backupTestRoot.'/public/brands/test.png', 'brand-icon-evidence');
        file_put_contents($this->backupTestRoot.'/private/recovery.txt', 'private-recovery-evidence');
        config(['backup-restore.private_paths' => ['recovery.txt']]);
        ProductImage::factory()->create(['path' => 'products/test.jpg']);
        Brand::factory()->create(['icon' => 'brands/test.png']);
        $before = DB::table('product_images')->orderBy('id')->get()->toJson();
        DB::statement('CREATE TABLE backup_engine_probe (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
        DB::statement('INSERT INTO backup_engine_probe VALUES (1, 23)');
        try {
            $transitions = [];
            BackupRun::updated(function (BackupRun $run) use (&$transitions): void {
                if ($run->wasChanged('status')) {
                    $transitions[] = $run->status;
                }
            });
            $run = $this->request();
            (new CreateFullBackup($run->uuid))->handle(app(BackupCoordinator::class));
            $run = $run->fresh();
            $this->assertSame('available', $run->status, 'Backup run failed with '.$run->failure_code);
            $this->assertSame('checksum_verified', $run->verification_status);
            $getObjectRequests = array_values(array_filter($this->s3->requests, static fn (array $request): bool => $request['name'] === 'GetObject'));
            $this->assertCount(1, $getObjectRequests);
            $this->assertSame(R2Storage::REQUEST_TIMEOUT_SECONDS, $getObjectRequests[0]['read_timeout']);
            $this->assertNotNull($run->verified_at);
            $this->assertSame(['capturing', 'encrypting', 'uploading', 'verifying', 'available'], $transitions);
            $this->assertSame(4, $run->file_count);
            $this->assertSame($before, DB::table('product_images')->orderBy('id')->get()->toJson());
            $this->assertSame(23, (int) DB::table('backup_engine_probe')->value('value'));
            $this->assertFalse($this->capture->held);
            $this->assertWorkspacesEmpty();
            $encrypted = $this->s3->disk->path($run->destination_snapshot['prefix'].'/'.$run->object_key);
            $this->assertSame($run->ciphertext_checksum, hash_file('sha256', $encrypted));
            $plain = $this->backupTestRoot.'/verified.plain';
            app(BackupEncryption::class)->decrypt($encrypted, $plain, fn () => null);
            $verified = app(BackupArchive::class)->verify($plain, fn () => null);
            $this->assertSame($run->consistency_mode, $verified['manifest']['consistency_mode']);
            $this->assertFalse($verified['manifest']['restore_tested']);
            $this->assertFalse($verified['manifest']['offline_pos_client_queues_included']);
            $this->assertSame(DB::connection()->getDatabaseName(), $verified['manifest']['database']['database']);
            $stream = fopen($plain, 'rb');
            fseek($stream, 6);
            $length = unpack('Nsize', fread($stream, 4))['size'];
            fseek($stream, 10 + $length);
            $sql = stream_get_contents($stream, $verified['manifest']['files'][0]['size']);
            fclose($stream);
            foreach (['CREATE TABLE `backup_engine_probe`', '-- Dump completed on'] as $evidence) {
                $this->assertStringContainsString($evidence, $sql);
            }
            $this->assertMatchesRegularExpression('/INSERT INTO `backup_engine_probe` VALUES\s*\(1,23\)/', $sql);
            $hashes = array_column($verified['manifest']['files'], 'sha256', 'path');
            foreach (['files/public/products/test.jpg' => 'product-image-evidence', 'files/public/brands/test.png' => 'brand-icon-evidence',
                'files/private/recovery.txt' => 'private-recovery-evidence'] as $path => $bytes) {
                $this->assertSame(hash('sha256', $bytes), $hashes[$path]);
            }
            $this->assertDatabaseHas('auth_events', ['event' => 'backups.available']);
            $this->assertFalse(BackupOperationLease::query()->findOrFail('global')->expires_at->isFuture());
        } finally {
            DB::statement('DROP TABLE IF EXISTS backup_engine_probe');
        }
    }

    public function test_capture_is_unconditionally_blocked_after_admission_removal(): void
    {
        app()->forgetInstance(CaptureProtection::class);
        $this->assertInstanceOf(UnavailableCaptureProtection::class, app(CaptureProtection::class));
        $this->assertSame(['capture_protection_unavailable'], app(CaptureProtection::class)->blockers());
        $this->assertSame([], $this->s3->commands);
    }

    public function test_media_snapshot_references_survive_product_and_brand_replacement_during_capture(): void
    {
        $this->enableNativeDdlBoundary();
        Module::query()->where('code', 'products')->update(['is_active' => true]);
        $manager = app(ProductImageManager::class);
        Storage::forgetDisk('public');
        $product = Product::factory()->create();
        $image = $manager->save($this->actor, $product, ['upload' => UploadedFile::fake()->image('product.png')]);
        $oldProduct = $image->path;
        $oldBrand = $manager->storeImmutableUpload(UploadedFile::fake()->image('brand.png'), 'brands/icons');
        $brand = Brand::factory()->create(['icon' => $oldBrand]);
        $oldHashes = [$oldProduct => hash_file('sha256', Storage::disk('public')->path($oldProduct)),
            $oldBrand => hash_file('sha256', Storage::disk('public')->path($oldBrand))];
        $this->capture->allowAdditionalMedia = true;
        $dumper = new class extends DatabaseDumper
        {
            public \Closure $afterDump;

            public function dump(BackupWorkspace $workspace, BackupLease $lease): array
            {
                $dump = parent::dump($workspace, $lease);
                ($this->afterDump)();

                return $dump;
            }
        };
        $dumper->afterDump = function () use ($manager, $product, $image, $brand): void {
            $manager->save($this->actor, $product, ['upload' => UploadedFile::fake()->image('replacement.png', 30, 30)], $image);
            $path = $manager->storeImmutableUpload(UploadedFile::fake()->image('new-brand.png', 30, 30), 'brands/icons');
            app(ProductCatalogManager::class)->save($this->actor, Brand::class, ['name' => $brand->name, 'icon' => $path], $brand);
        };
        app()->instance(DatabaseDumper::class, $dumper);
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $run = $run->fresh();
        $this->assertSame('available', $run->status, $run->failure_code ?? '');
        $this->assertNotSame($oldProduct, $image->fresh()->path);
        $this->assertNotSame($oldBrand, $brand->fresh()->icon);
        $encrypted = $this->s3->disk->path($run->destination_snapshot['prefix'].'/'.$run->object_key);
        $plain = $this->backupTestRoot.'/media-verified.plain';
        app(BackupEncryption::class)->decrypt($encrypted, $plain, static fn () => null);
        $manifest = app(BackupArchive::class)->verify($plain, static fn () => null)['manifest'];
        $references = array_column($manifest['database']['media_references'], 'path');
        $hashes = array_column($manifest['files'], 'sha256', 'path');
        foreach ($oldHashes as $path => $hash) {
            $this->assertContains($path, $references);
            $this->assertContains('files/public/'.$path, $run->destination_snapshot['media_references']);
            $this->assertSame($hash, $hashes['files/public/'.$path]);
            $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($path)));
        }
        $this->assertNotContains($image->fresh()->path, $references);
        $this->assertNotContains($brand->fresh()->icon, $references);
        $this->assertSame('checksum_verified', $run->verification_status);
        $this->assertWorkspacesEmpty();
    }

    public function test_native_ddl_owner_loss_after_dump_fails_without_publishing_an_archive(): void
    {
        $account = $this->enableNativeDdlBoundary();
        $dumper = \Mockery::mock(DatabaseDumper::class)->makePartial();
        $dumper->shouldReceive('dump')->once()->andReturnUsing(function (BackupWorkspace $workspace, BackupLease $lease) use ($account): array {
            $dump = (new DatabaseDumper)->dump($workspace, $lease);
            $owners = DB::table('information_schema.PROCESSLIST')->where('USER', $account)->pluck('ID');
            $this->assertCount(1, $owners);
            DB::statement('KILL CONNECTION '.(int) $owners[0]);

            return $dump;
        });
        app()->instance(DatabaseDumper::class, $dumper);
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $run = $run->fresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('capture_protection_unavailable', $run->failure_code);
        $this->assertSame('pending', $run->verification_status);
        $this->assertSame([], $this->s3->commands);
        $this->assertFalse($this->capture->held);
        $this->assertWorkspacesEmpty();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame([], $this->s3->commands);
    }

    public function test_isolated_capture_rejects_operational_identity_media_aliases_and_open_transactions(): void
    {
        foreach (['fox_hema_pos', 'e_commerce'] as $name) {
            try {
                (new IsolatedBackupCapture($name, $this->backupTestRoot))->blockers();
                $this->fail('Operational identity accepted by the disposable fixture');
            } catch (BackupFailure $failure) {
                $this->assertSame('capture_protection_unavailable', $failure->getMessage());
            }
        }
        config(['filesystems.disks.public.root' => $this->backupTestRoot.'/private']);
        try {
            $this->capture->blockers();
            $this->fail('Media alias accepted');
        } catch (BackupFailure $failure) {
            $this->assertSame('capture_protection_unavailable', $failure->getMessage());
        } finally {
            config(['filesystems.disks.public.root' => $this->backupTestRoot.'/public']);
        }
        $run = $this->request();
        $lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        DB::beginTransaction();
        try {
            $this->capture->capture($lease, fn () => $this->fail('Open transaction capture executed'));
            $this->fail('Open transaction accepted');
        } catch (BackupFailure $failure) {
            $this->assertSame('capture_protection_unavailable', $failure->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertFalse($this->capture->held);
        $this->assertSame([], $this->s3->commands);
    }

    public function test_isolated_capture_checks_owner_and_media_across_the_whole_window(): void
    {
        $run = $this->request();
        $lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        $forged = new BackupLease($lease->runUuid, bin2hex(random_bytes(32)), $lease->fence);
        try {
            $this->capture->capture($lease, fn () => $this->capture->assertHeld($forged));
            $this->fail('A different owner was accepted');
        } catch (BackupFailure $failure) {
            $this->assertSame('capture_protection_unavailable', $failure->getMessage());
        }
        $source = $this->backupTestRoot.'/public/stable.txt';
        file_put_contents($source, 'before');
        try {
            $this->capture->capture($lease, fn () => file_put_contents($source, 'after'));
            $this->fail('Window-wide media change accepted');
        } catch (BackupFailure $failure) {
            $this->assertSame('files_changed', $failure->getMessage());
        }
        $this->assertFalse($this->capture->held);
    }

    public function test_interruption_after_native_dump_fails_cleans_and_never_retries_the_attempt(): void
    {
        $native = new DatabaseDumper;
        $dumper = \Mockery::mock(DatabaseDumper::class)->makePartial();
        $dumpCompleted = false;
        $dumper->shouldReceive('dump')->once()->andReturnUsing(function (BackupWorkspace $workspace, BackupLease $lease) use ($native, &$dumpCompleted): array {
            $result = $native->dump($workspace, $lease);
            $dumpCompleted = is_file($result['path']);
            throw new BackupFailure('worker_interrupted');
        });
        app()->instance(DatabaseDumper::class, $dumper);
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertTrue($dumpCompleted);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('worker_interrupted', $run->fresh()->failure_code);
        $this->assertSame('pending', $run->fresh()->verification_status);
        $this->assertFalse($this->capture->held);
        $this->assertSame([], $this->s3->commands);
        $this->assertWorkspacesEmpty();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertDatabaseCount('backup_runs', 1);
        $this->assertDatabaseHas('auth_events', ['event' => 'backups.failed']);
    }

    public function test_production_guard_refuses_requests_without_creating_records_or_jobs(): void
    {
        app()->instance(CaptureProtection::class, new UnavailableCaptureProtection);
        config(['backup-restore.write_gate_enabled' => true]);
        try {
            $this->request();
            $this->fail('An unprotected capture was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('capture_protection_unavailable', $failure->getMessage());
        }
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertDatabaseCount('backup_operation_leases', 0);
        Queue::assertNothingPushed();
        $this->assertDirectoryDoesNotExist($this->backupTestRoot.'/workspaces');
    }

    public function test_idempotent_requests_after_commit_dispatch_and_duplicate_jobs(): void
    {
        $nonce = (string) Str::uuid();
        DB::beginTransaction();
        $run = app(BackupCoordinator::class)->request($this->actor, $nonce);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(CreateFullBackup::class, 1);
        $again = app(BackupCoordinator::class)->request($this->actor, $nonce);
        $this->assertSame($run->id, $again->id);
        Queue::assertPushed(CreateFullBackup::class, 1);
        app(BackupCoordinator::class)->execute($run->uuid);
        $count = count($this->s3->commands);
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('available', $run->fresh()->status);
        $this->assertSame($count, count($this->s3->commands));
        $this->assertDatabaseCount('backup_runs', 1);
    }

    public function test_rolled_back_request_never_dispatches_a_job(): void
    {
        DB::beginTransaction();
        $this->request();
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('backup_runs', 0);
    }

    #[DataProvider('storageFailures')]
    public function test_storage_failures_never_publish_and_never_leak_credentials(string $mode, string $code): void
    {
        if ($mode === 'corrupt') {
            $this->s3->corruptReadback = true;
        } elseif ($mode === 'truncate') {
            $this->s3->truncateReadback = true;
        } else {
            $this->s3->failCommand = $mode;
        }
        Log::spy();
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($code, $run->fresh()->failure_code);
        $this->assertSame('pending', $run->fresh()->verification_status);
        $this->assertStringNotContainsString('fixture-secret', $run->fresh()->toJson());
        $this->assertStringNotContainsString('fixture-access', AuthEvent::query()->get()->toJson());
        $this->assertStringNotContainsString('fixture-secret', AuthEvent::query()->get()->toJson());
        $payload = serialize(new CreateFullBackup($run->uuid));
        $this->assertStringNotContainsString('fixture-secret', $payload);
        $this->assertFalse(str_contains($payload, trim(file_get_contents($this->backupKeyPath))));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertFalse($this->capture->held);
        $this->assertWorkspacesEmpty();
        if ($mode === 'UploadPart') {
            $this->assertSame(1, $this->s3->aborts);
        }
    }

    public static function storageFailures(): array
    {
        return [['UploadPart', 'upload_failed'], ['GetObject', 'readback_failed'], ['corrupt', 'checksum_mismatch'], ['truncate', 'checksum_mismatch']];
    }

    public function test_missing_referenced_files_fail_with_restricted_record_evidence(): void
    {
        $image = ProductImage::factory()->create(['path' => 'products/missing.jpg']);
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('missing_media', $run->fresh()->failure_code);
        $this->assertSame([$image->id], $run->fresh()->destination_snapshot['failure_evidence']['product_image_ids']);
        $this->assertSame([], $this->s3->commands);
        $this->assertWorkspacesEmpty();
    }

    public function test_expired_lease_recovery_cleans_plaintext_and_fences_the_stale_worker(): void
    {
        $run = $this->request();
        $manager = app(BackupLeaseManager::class);
        $old = $manager->acquire($run->uuid);
        $workspace = BackupWorkspace::forLease($old);
        file_put_contents($workspace->file('database.sql'), 'interrupted plaintext');
        $this->travel(91)->seconds();
        $result = app(BackupCoordinator::class)->recover($this->actor);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame(1, $result['cleaned_workspaces']);
        $this->assertSame('worker_interrupted', $run->fresh()->failure_code);
        $this->assertDirectoryDoesNotExist($workspace->path);
        $this->assertSame(0, app(BackupCoordinator::class)->recover($this->actor)['recovered']);
        $next = $this->request();
        $current = $manager->acquire($next->uuid);
        $this->assertGreaterThan($old->fence, $current->fence);
        $manager->release($old);
        $manager->heartbeat($current);
        try {
            $manager->transition($old, 'capturing', 'encrypting');
            $this->fail('A stale worker advanced the lifecycle.');
        } catch (BackupFailure $failure) {
            $this->assertSame('lease_lost', $failure->getMessage());
        }
        $this->assertSame('capturing', $next->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_recovery_preserves_live_workspaces_and_does_not_retry_stale_jobs(): void
    {
        $run = $this->request();
        $lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        $workspace = BackupWorkspace::forLease($lease);
        file_put_contents($workspace->file('database.sql'), 'live capture');
        $this->assertSame(0, app(BackupCoordinator::class)->recover($this->actor)['cleaned_workspaces']);
        $this->assertFileExists($workspace->file('database.sql'));
        $this->assertSame('capturing', $run->fresh()->status);
        Queue::assertPushed(CreateFullBackup::class, 1);
    }

    public function test_stale_queued_request_fails_without_deleting_or_retrying_its_job(): void
    {
        $run = $this->request();
        $run->update(['created_at' => now()->subSeconds(901)]);
        $result = app(BackupCoordinator::class)->recover($this->actor);
        $this->assertSame(1, $result['recovered']);
        $this->assertSame('queue_dispatch_failed', $run->fresh()->failure_code);
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame([], $this->s3->commands);
        Queue::assertPushed(CreateFullBackup::class, 1);
    }

    public function test_incomplete_multipart_upload_is_aborted_after_worker_crash(): void
    {
        $run = $this->request();
        app(BackupLeaseManager::class)->acquire($run->uuid);
        $snapshot = $run->destination_snapshot;
        $snapshot['multipart_upload_id'] = 'interrupted-upload';
        $run->update(['object_key' => 'runs/'.$run->uuid.'/attempt-test.fhbk', 'destination_snapshot' => $snapshot]);
        $this->s3->uploads['interrupted-upload'] = ['key' => $run->object_key, 'parts' => [1 => 'ciphertext']];
        $this->travel(91)->seconds();
        $result = app(BackupCoordinator::class)->recover($this->actor);
        $this->assertSame(0, $result['multipart_abort_failures']);
        $this->assertSame(1, $this->s3->aborts);
        $this->assertSame([], $this->s3->uploads);
        $this->assertNotContains('DeleteObject', $this->s3->commands);
    }

    public function test_dump_authentication_failure_is_sanitized_and_cleans_every_plaintext_file(): void
    {
        $original = DB::connection();
        $configuration = $original->getConfig();
        $configuration['password'] = 'fixture-wrong-dump-password';
        $connection = new MySqlConnection($original->getPdo(), $original->getDatabaseName(), '', $configuration);
        $dumper = \Mockery::mock(DatabaseDumper::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $dumper->shouldReceive('connection')->andReturn($connection);
        $commands = [];
        $dumper->shouldReceive('process')->andReturnUsing(function (array $arguments) use (&$commands): Process {
            $commands[] = $arguments;
            $this->assertFalse(str_contains(implode(' ', $arguments), 'fixture-wrong-dump-password'));
            $this->assertNotContains('--all-databases', $arguments);

            return new Process($arguments);
        });
        app()->instance(DatabaseDumper::class, $dumper);
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('dump_failed', $run->fresh()->failure_code);
        $this->assertNotEmpty($commands);
        $this->assertSame([], $this->s3->commands);
        $this->assertWorkspacesEmpty();
        $this->assertStringNotContainsString('fixture-wrong-dump-password', AuthEvent::query()->get()->toJson());
    }

    public function test_unknown_private_files_block_a_supposedly_complete_capture(): void
    {
        file_put_contents($this->backupTestRoot.'/private/unclassified-attachment.pdf', 'required?');
        $run = $this->request();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('private_files_unclassified', $run->fresh()->failure_code);
        $this->assertSame([], $this->s3->commands);
        $this->assertWorkspacesEmpty();
    }

    public function test_unsafe_media_link_is_rejected_without_following_it(): void
    {
        $outside = $this->backupTestRoot.'/outside';
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'must remain untouched');
        $link = $this->backupTestRoot.'/public/unsafe-link';
        if (! @symlink($outside, $link)) {
            $binary = (getenv('SystemRoot') ?: 'C:\Windows').'\System32\cmd.exe';
            $process = new Process([$binary, '/d', '/c', 'mklink', '/J', str_replace('/', '\\', $link), str_replace('/', '\\', $outside)]);
            $process->setTimeout(10)->mustRun();
        }
        try {
            $run = $this->request();
            app(BackupCoordinator::class)->execute($run->uuid);
            $this->assertSame('unsafe_path', $run->fresh()->failure_code);
            $this->assertSame('must remain untouched', file_get_contents($outside.'/keep.txt'));
            $this->assertWorkspacesEmpty();
        } finally {
            if (is_dir($link)) {
                rmdir($link);
            } elseif (is_link($link)) {
                unlink($link);
            }
        }
    }

    public function test_source_mutation_is_detected_and_capture_guard_is_released_after_failure(): void
    {
        $source = $this->backupTestRoot.'/public/changing.dat';
        file_put_contents($source, str_repeat('a', 2 * 1024 * 1024));
        $run = $this->request();
        $lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        $workspace = BackupWorkspace::forLease($lease);
        $checks = 0;
        $this->capture->onCheck = function () use (&$checks, $source): void {
            if (++$checks === 3) {
                file_put_contents($source, str_repeat('b', 2 * 1024 * 1024));
            }
        };
        try {
            $this->capture->capture($lease, fn () => app(FileSnapshot::class)->capture($workspace, $lease, []));
            $this->fail('Changing source media was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('files_changed', $failure->getMessage());
            $this->assertFalse($this->capture->held);
        }
    }

    public function test_backend_rechecks_global_authorization_and_queued_requester_revocation(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $branch = Location::query()->create(['name' => 'Backup branch', 'type' => 'branch']);
        $other->locations()->attach($branch);
        foreach ([null, $branch->id] as $scope) {
            setPermissionsTeamId($scope);
            try {
                app(BackupCoordinator::class)->request($other, (string) Str::uuid());
                $this->fail('An unauthorized request was accepted.');
            } catch (HttpException $failure) {
                $this->assertSame(403, $failure->getStatusCode());
            }
        }
        setPermissionsTeamId(null);
        $run = $this->request();
        Role::query()->where('name', 'super_admin')->whereNull('location_id')->sole()->revokePermissionTo('backups.run');
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('permission_revoked', $run->fresh()->failure_code);
        $this->assertSame([], $this->s3->commands);
    }

    public function test_history_shows_disabled_manual_action_reasons_and_localized_status(): void
    {
        app()->instance(CaptureProtection::class, new UnavailableCaptureProtection);
        Livewire::test(BackupHistory::class)->assertActionDisabled('manualFullBackup')->assertSee(__('backup_restore.errors.capture_protection_unavailable'))
            ->call('requestManualBackup')->assertHasNoErrors();
        $this->assertDatabaseCount('backup_runs', 0);
        app()->setLocale('ar');
        Livewire::test(BackupHistory::class)->assertActionDisabled('manualFullBackup')->assertSee('متطلبات النسخ اليدوي غير مكتملة')->assertSeeHtml('dir="rtl"');
        $this->assertSame(array_keys(__('backup_restore.errors', [], 'en')), array_keys(__('backup_restore.errors', [], 'ar')));
    }

    public function test_display_readiness_reuses_environment_checks_but_execution_rechecks_them(): void
    {
        $connection = DB::connection();
        $clientCalls = 0;
        $dumper = \Mockery::mock(DatabaseDumper::class);
        $dumper->shouldReceive('connection')->twice()->andReturn($connection);
        $dumper->shouldReceive('client')->twice()->andReturnUsing(function () use (&$clientCalls): array {
            if (++$clientCalls === 2) {
                throw new BackupFailure('dump_incompatible');
            }

            return ['binary' => config('backup-restore.dump_binary'), 'family' => 'mariadb', 'version' => 'MariaDB 10.4.32'];
        });
        app()->instance(DatabaseDumper::class, $dumper);

        $keyProvider = \Mockery::mock(BackupKeyProvider::class);
        $keyProvider->shouldReceive('withKey')->twice()->andReturn(true);
        app()->instance(BackupKeyProvider::class, $keyProvider);

        $readiness = app(BackupReadiness::class);
        $this->assertSame([], $readiness->displayBlockers());
        $this->assertSame([], $readiness->displayBlockers());

        try {
            $readiness->assertReady();
            $this->fail('Execution readiness reused a successful display result.');
        } catch (BackupFailure $failure) {
            $this->assertSame('dump_incompatible', $failure->getMessage());
        }
    }

    #[DataProvider('concurrentRequestModes')]
    public function test_concurrent_manual_requests_are_serialized_and_dispatch_only_once(bool $sameNonce): void
    {
        $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $root = $argv[2];
        config(['backup-restore.dump_binary' => 'C:/xampp/mysql/bin/mysqldump.exe', 'backup-restore.release_id' => 'test-release',
          'backup-restore.workspace_root' => $root.'/workspaces', 'backup-restore.key_files.v1' => $root.'/keys/v1.key']);
        config(['filesystems.disks.public.root' => $root.'/public', 'filesystems.disks.local.root' => $root.'/private']);
        app()->instance(App\Modules\BackupRestore\Services\CaptureProtection::class, new Tests\Support\IsolatedBackupCapture(
          Illuminate\Support\Facades\DB::connection()->getDatabaseName(), $root));
        Illuminate\Support\Facades\Queue::fake();
        try {
          $actor = App\Modules\AccessManagement\Models\User::query()->where('uuid',$argv[3])->firstOrFail();
          $run = app(App\Modules\BackupRestore\Services\BackupCoordinator::class)->request($actor,$argv[1]);
          echo json_encode(['uuid'=>$run->uuid,'pushed'=>Illuminate\Support\Facades\Queue::pushed(App\Modules\BackupRestore\Jobs\CreateFullBackup::class)->count()]);
        } catch (App\Modules\BackupRestore\Services\BackupFailure $failure) { echo json_encode(['failure'=>$failure->getMessage(),'pushed'=>0]); }
        PHP;
        $nonce = (string) Str::uuid();
        $first = new Process([PHP_BINARY, '-r', $code, $nonce, $this->backupTestRoot, $this->actor->uuid], base_path());
        $second = new Process([PHP_BINARY, '-r', $code, $sameNonce ? $nonce : (string) Str::uuid(), $this->backupTestRoot, $this->actor->uuid], base_path());
        $first->setTimeout(60)->start();
        $second->setTimeout(60)->start();
        $first->wait();
        $second->wait();
        $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $a = json_decode($first->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $b = json_decode($second->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $a['pushed'] + $b['pushed']);
        $this->assertDatabaseCount('backup_runs', 1);
        if ($sameNonce) {
            $this->assertSame($a['uuid'], $b['uuid']);
        } else {
            $this->assertContains('lease_busy', [$a['failure'] ?? null, $b['failure'] ?? null]);
        }
    }

    public static function concurrentRequestModes(): array
    {
        return [[true], [false]];
    }

    public function test_missing_infrastructure_is_reported_without_enqueuing_a_backup(): void
    {
        BackupDestination::query()->update(['probe_status' => 'not_checked']);
        config(['backup-restore.key_files.v1' => null, 'backup-restore.dump_binary' => null, 'backup-restore.release_id' => null,
            'queue.connections.backup_database.retry_after' => 10]);
        $this->assertEqualsCanonicalizing(['r2_not_configured', 'key_unavailable', 'dump_binary_unavailable', 'queue_unavailable', 'release_unconfigured'], app(BackupReadiness::class)->blockers());
        try {
            $this->request();
            $this->fail('Missing infrastructure was ignored.');
        } catch (BackupFailure) {
            $this->assertDatabaseCount('backup_runs', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_dispatch_failure_is_durable_and_sanitized_after_request_commit(): void
    {
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('fixture-secret dispatch detail'));
        try {
            $this->request();
            $this->fail('The dispatch failure was ignored.');
        } catch (BackupFailure $failure) {
            $this->assertSame('queue_dispatch_failed', $failure->getMessage());
            $this->assertDatabaseHas('backup_runs', ['status' => 'failed', 'failure_code' => 'queue_dispatch_failed']);
            $this->assertDatabaseCount('backup_runs', 1);
            $this->assertStringNotContainsString('fixture-secret', AuthEvent::query()->get()->toJson());
        }
    }

    public function test_recovery_clears_a_durable_cleanup_marker_only_after_workspace_removal(): void
    {
        $run = $this->request();
        $lease = app(BackupLeaseManager::class)->acquire($run->uuid);
        $workspace = BackupWorkspace::forLease($lease);
        file_put_contents($workspace->file('database.sql'), 'abandoned plaintext');
        $snapshot = $run->destination_snapshot;
        $snapshot['cleanup_required'] = true;
        $run->update(['status' => 'failed', 'failure_code' => 'private_storage_unavailable', 'destination_snapshot' => $snapshot]);
        app(BackupLeaseManager::class)->release($lease);
        $result = app(BackupCoordinator::class)->recover($this->actor);
        $this->assertSame(1, $result['cleaned_workspaces']);
        $this->assertDirectoryDoesNotExist($workspace->path);
        $this->assertArrayNotHasKey('cleanup_required', $run->fresh()->destination_snapshot);
        $this->assertNotContains('DeleteObject', $this->s3->commands);
    }

    public function test_unrelated_database_configuration_is_rejected_before_any_query(): void
    {
        $database = config('database.connections.mysql.database');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        DB::purge('mysql');
        config(['database.connections.mysql.database' => 'e_commerce']);
        try {
            app(DatabaseDumper::class)->connection();
            $this->fail('An unrelated database configuration was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('database_identity_mismatch', $failure->getMessage());
            $this->assertSame(0, $queries);
        } finally {
            DB::purge('mysql');
            config(['database.connections.mysql.database' => $database]);
        }
    }

    public function test_online_manual_capture_uses_native_guard_and_dedicated_dump_account(): void
    {
        $this->enableNativeDdlBoundary();
        $this->assertInstanceOf(OnlineCaptureProtection::class, app(CaptureProtection::class));
        $this->test_real_isolated_dump_media_encryption_upload_and_readback_become_available();
        $run = BackupRun::query()->sole();
        $encrypted = $this->s3->disk->path($run->destination_snapshot['prefix'].'/'.$run->object_key);
        $response = $this->get(route('backups.download', ['uuid' => $run->uuid]));
        $response->assertOk()->assertDownload('fox-hema-backup-'.$run->uuid.'.fhbk');
        $this->assertSame(file_get_contents($encrypted), $response->streamedContent());
        $this->assertWorkspacesEmpty();
    }

    public function test_online_manual_filament_action_dispatches_the_existing_capture_job(): void
    {
        $this->enableNativeDdlBoundary();
        Livewire::test(BackupHistory::class)->assertActionEnabled('manualFullBackup')
            ->call('requestManualBackup')->assertHasNoErrors();
        Queue::assertPushed(CreateFullBackup::class, 1);
        $run = BackupRun::query()->sole();
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame('available', $run->fresh()->status, $run->fresh()->failure_code ?? '');
        $this->assertSame('online_transactional', $run->fresh()->consistency_mode);
    }

    public function test_online_capture_rejects_nontransactional_tables_and_allows_a_new_request_after_failure(): void
    {
        $this->enableNativeDdlBoundary();
        DB::statement('CREATE TABLE backup_nontransactional_probe (id INT) ENGINE=MyISAM');
        try {
            $run = $this->request();
            app(BackupCoordinator::class)->execute($run->uuid);
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('dump_incompatible', $run->fresh()->failure_code);
            $this->assertSame([], $this->s3->commands);
            $this->assertWorkspacesEmpty();
        } finally {
            DB::statement('DROP TABLE backup_nontransactional_probe');
        }
        app(BackupCoordinator::class)->execute($run->uuid);
        $this->assertSame([], $this->s3->commands);
        $retry = $this->request();
        app(BackupCoordinator::class)->execute($retry->uuid);
        $this->assertSame('available', $retry->fresh()->status, $retry->fresh()->failure_code ?? '');
    }

    public function test_online_schedule_uses_same_capture_and_is_idempotent(): void
    {
        $this->enableNativeDdlBoundary();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:05:00', 'UTC'));
        config(['backup-restore.automation.controller_enabled' => true, 'backup-restore.automation.scheduling_approved' => true]);
        BackupPolicy::factory()->create([
            'id' => 1, 'destination_id' => BackupDestination::query()->firstOrFail()->id,
            'timezone' => 'UTC', 'daily_time' => '12:00:00', 'schedule_requested' => true,
            'schedule_requested_at' => now()->subDay(),
        ]);
        $scheduler = app(ScheduledBackups::class);
        $this->assertSame('dispatched', $scheduler->tick());
        $this->assertSame('dispatched', $scheduler->tick());
        Queue::assertPushed(CreateFullBackup::class, 1);
        $run = BackupRun::query()->sole();
        $this->assertSame('scheduled', $run->trigger);
        (new CreateFullBackup($run->uuid))->handle(app(BackupCoordinator::class));
        $this->assertSame('available', $run->fresh()->status, $run->fresh()->failure_code ?? '');
        $this->assertSame('online_transactional', $run->fresh()->consistency_mode);
        $this->assertWorkspacesEmpty();
    }

    public function test_online_configuration_and_current_filament_prerequisites_fail_closed(): void
    {
        app()->forgetInstance(CaptureProtection::class);
        $this->assertInstanceOf(UnavailableCaptureProtection::class, app(CaptureProtection::class));
        $this->assertFalse(config('backup-restore.capture.enabled'));
        $this->assertFalse(config('backup-restore.automation.controller_enabled'));
        $this->assertFalse(config('backup-restore.restore.enabled'));
        $this->assertTrue($this->actor->hasPermissionTo('backups.download', 'web'));
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(BackupHistory::class)->assertSuccessful()
                ->assertSee(__('backup_restore.errors.capture_protection_unavailable'))
                ->assertDontSee('Livewire')->assertDontSee('Write admission');
            Livewire::test(BackupSettings::class)
                ->assertSuccessful()->assertSee(__('backup_restore.online_capture_help'))
                ->assertSee(__('backup_restore.errors.capture_accounts_unapproved'));
        }
        config(['backup-restore.capture.enabled' => true]);
        app()->forgetInstance(CaptureProtection::class);
        $this->assertContains('capture_accounts_unapproved', app(CaptureProtection::class)->blockers());
        try {
            $this->request();
            $this->fail('Missing approval must prevent enqueue.');
        } catch (BackupFailure) {
            $this->assertDatabaseCount('backup_runs', 0);
            Queue::assertNothingPushed();
        }
    }

    private function enableNativeDdlBoundary(): string
    {
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $account = 'fh_ddl_engine_'.bin2hex(random_bytes(6));
        DB::unprepared("CREATE USER '".$account."'@'127.0.0.1' IDENTIFIED BY 'disposable-fixture-only'");
        $this->schemaLockAccounts[] = $account;
        DB::unprepared("GRANT RELOAD ON *.* TO '".$account."'@'127.0.0.1'");
        $dumpAccount = 'fh_ddl_dump_'.bin2hex(random_bytes(6));
        $this->schemaLockAccounts[] = $dumpAccount;
        DB::unprepared("CREATE USER '".$dumpAccount."'@'127.0.0.1' IDENTIFIED BY 'disposable-fixture-only'");
        $schema = DB::connection()->getDatabaseName();
        DB::unprepared('GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON '.$schema.".* TO '".$dumpAccount."'@'127.0.0.1'");
        DB::unprepared("GRANT SELECT ON mysql.proc TO '".$dumpAccount."'@'127.0.0.1'");
        $configuration = DB::connection()->getConfig();
        config(['backup-restore.capture.enabled' => true, 'backup-restore.capture.accounts_approved' => true,
            'backup-restore.capture.instance_lock_approved' => true,
            'backup-restore.capture.guard_connection' => 'fixture_guard', 'backup-restore.capture.dump_connection' => 'fixture_dump',
            'database.connections.fixture_guard' => array_replace($configuration, ['username' => $account, 'password' => 'disposable-fixture-only']),
            'database.connections.fixture_dump' => array_replace($configuration, ['username' => $dumpAccount, 'password' => 'disposable-fixture-only'])]);
        app()->forgetInstance(CaptureProtection::class);

        return $account;
    }

    private function request(): BackupRun
    {
        return app(BackupCoordinator::class)->request($this->actor, (string) Str::uuid());
    }

    private function assertWorkspacesEmpty(): void
    {
        $root = $this->backupTestRoot.'/workspaces';
        $this->assertSame([], is_dir($root) ? array_values(array_diff(scandir($root), ['.', '..'])) : []);
    }
}
