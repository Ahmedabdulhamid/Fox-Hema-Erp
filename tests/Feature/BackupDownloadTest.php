<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\BackupHistory;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupDownload;
use App\Modules\BackupRestore\Services\BackupEncryption;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupTransport;
use App\Modules\BackupRestore\Services\R2Storage;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BackupFileEnvironment;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\Support\BackupS3Fake;
use Tests\Support\FakeBackupR2Storage;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use BackupFileEnvironment;
    use UsesIsolatedDatabase;

    private User $actor;

    private BackupRun $run;

    private BackupS3Fake $s3;

    private BackupTransport $transport;

    private string $bytes;

    private string $fakeDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(33316, (int) DB::connection()->getConfig('port'));
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        $this->setUpBackupFiles();
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        setPermissionsTeamId(null);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $disk = Storage::fake('backup-engine-r2-'.bin2hex(random_bytes(8)));
        $this->fakeDiskRoot = $disk->path('');
        $this->s3 = new BackupS3Fake($disk);
        app()->instance(R2Storage::class, new FakeBackupR2Storage($this->s3));
        $destination = BackupDestination::factory()->create(['status' => 'active'])->fresh();
        $attempt = (string) Str::uuid();
        $this->run = BackupRun::factory()->create([
            'destination_id' => $destination->id, 'status' => 'queued', 'verification_status' => 'checksum_verified',
            'verified_at' => now(), 'completed_at' => now(), 'archive_format' => 'fhbk-v1/fhar-v1', 'encryption_key_id' => 'v1',
            'destination_snapshot' => ['endpoint' => $destination->endpoint, 'bucket' => $destination->bucket, 'prefix' => $destination->prefix,
                'configuration_version' => $destination->configuration_version, 'attempt_uuid' => $attempt, 'fence' => 1],
        ]);
        file_put_contents($this->backupTestRoot.'/fixture.txt', 'synthetic-archive-contents');
        app(BackupEncryption::class)->encrypt($this->backupTestRoot.'/fixture.txt', $this->backupTestRoot.'/encrypted.fhbk', 'v1',
            ['run_uuid' => $this->run->uuid, 'attempt_uuid' => $attempt, 'fence' => 1], static function (): void {});
        $this->bytes = file_get_contents($this->backupTestRoot.'/encrypted.fhbk');
        $this->run->update(['status' => 'available', 'object_key' => 'runs/'.$this->run->uuid.'/attempt-'.$attempt.'.fhbk',
            'ciphertext_checksum' => hash('sha256', $this->bytes), 'size_bytes' => strlen($this->bytes), 'manifest_checksum' => str_repeat('b', 64)]);
        $disk->put($destination->prefix.'/'.$this->run->object_key, $this->bytes);
        $this->transport = new class extends BackupTransport
        {
            public ?string $temporaryPath = null;

            public ?Closure $afterReceive = null;

            public function downloadToStream(BackupRun $run, mixed $output, Closure $tick): void
            {
                $this->temporaryPath = stream_get_meta_data($output)['uri'];
                parent::downloadToStream($run, $output, $tick);
                if ($this->afterReceive !== null) {
                    ($this->afterReceive)();
                }
            }
        };
        app()->instance(BackupTransport::class, $this->transport);
    }

    protected function tearDown(): void
    {
        foreach (['backupTestRoot', 'fakeDiskRoot'] as $property) {
            if (isset($this->{$property})) {
                $this->removeBackupTestDirectory($this->{$property});
            }
        }
        parent::tearDown();
    }

    public function test_authorized_download_sends_exact_original_encrypted_bytes_and_cleans_temporary_file(): void
    {
        $response = $this->get($this->url().'?object_key=untrusted&bucket=other');
        $response->assertOk()->assertDownload('fox-hema-backup-'.$this->run->uuid.'.fhbk')
            ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Content-Length', (string) strlen($this->bytes));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertFileExists($this->transport->temporaryPath);
        $this->assertSame($this->bytes, $response->streamedContent());
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
        $this->assertSame(['HeadObject', 'GetObject'], $this->s3->commands);
        $this->assertSame('test-backups/'.$this->run->object_key, $this->s3->requests[1]['key']);
    }

    public function test_view_only_and_unprivileged_users_cannot_download_and_anonymous_requests_are_denied(): void
    {
        $other = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actingAs($other)->getJson($this->url())->assertForbidden();
        $other->givePermissionTo('backups.view');
        $this->getJson($this->url())->assertForbidden();
        auth()->forgetUser();
        $this->getJson($this->url())->assertUnauthorized();
        $this->assertSame([], $this->s3->commands);
    }

    #[DataProvider('unavailableArchives')]
    public function test_unavailable_or_substituted_artifacts_are_rejected_before_storage_access(array $changes): void
    {
        $this->run->update($changes);
        $this->getJson($this->url())->assertStatus(409);
        $this->assertSame([], $this->s3->commands);
    }

    public static function unavailableArchives(): array
    {
        return ['deleted' => [['status' => 'deleted']], 'deleting' => [['status' => 'deleting']],
            'failed_deletion' => [['status' => 'deletion_failed']], 'unverified' => [['status' => 'verifying', 'verification_status' => 'pending']],
            'failed_verification' => [['status' => 'failed', 'verification_status' => 'failed']], 'cross_run_key' => [['object_key' => 'runs/'.'00000000-0000-4000-8000-000000000000/attempt-other.fhbk']],
            'traversal' => [['object_key' => '../other.fhbk']], 'plaintext_format' => [['archive_format' => 'sql']]];
    }

    public function test_corrupt_and_truncated_readback_is_rejected_without_archive_bytes_and_cleans_temp_files(): void
    {
        foreach (['corruptReadback', 'truncateReadback'] as $mode) {
            $this->s3->{$mode} = true;
            $this->getJson($this->url())->assertStatus(409)->assertJsonPath('message', __('backup_restore.download_failed'));
            $this->assertFileDoesNotExist($this->transport->temporaryPath);
            $this->s3->{$mode} = false;
        }
    }

    public function test_head_size_mismatch_and_storage_errors_fail_closed_and_cleanup(): void
    {
        $this->run->update(['size_bytes' => strlen($this->bytes) + 1]);
        $this->getJson($this->url())->assertStatus(409);
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
        $this->run->update(['size_bytes' => strlen($this->bytes)]);
        $this->s3->failCommand = 'GetObject';
        $response = $this->getJson($this->url())->assertStatus(409);
        $this->assertStringNotContainsString('fixture-secret', $response->getContent());
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
    }

    public function test_revocation_or_deletion_during_staging_prevents_delivery(): void
    {
        $this->transport->afterReceive = fn () => $this->run->update(['status' => 'deleted']);
        $this->getJson($this->url())->assertStatus(409);
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
        $this->run->update(['status' => 'available']);
        $this->transport->afterReceive = fn () => Role::query()->where('name', 'super_admin')->whereNull('location_id')->sole()->revokePermissionTo('backups.download');
        $this->getJson($this->url())->assertForbidden();
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
    }

    public function test_destination_change_is_rejected_and_restore_verified_archives_are_supported(): void
    {
        $destination = $this->run->destination;
        $destination->update(['configuration_version' => $destination->configuration_version + 1]);
        $this->getJson($this->url())->assertStatus(409);
        $this->assertSame([], $this->s3->commands);
        $destination->update(['configuration_version' => $destination->configuration_version - 1]);
        $this->run->update(['verification_status' => 'restore_verified']);
        $this->assertSame($this->bytes, $this->get($this->url())->assertOk()->streamedContent());
    }

    public function test_abandoned_response_releases_its_temporary_stream_without_sending_bytes(): void
    {
        $response = app(BackupDownload::class)->response($this->actor, $this->run->uuid);
        $path = $this->transport->temporaryPath;
        $this->assertFileExists($path);
        unset($response);
        gc_collect_cycles();
        $this->assertFileDoesNotExist($path);
    }

    public function test_another_runs_envelope_is_rejected_even_with_a_matching_size_and_checksum(): void
    {
        $path = $this->backupTestRoot.'/other.fhbk';
        app(BackupEncryption::class)->encrypt($this->backupTestRoot.'/fixture.txt', $path, 'v1',
            ['run_uuid' => (string) Str::uuid(), 'attempt_uuid' => $this->run->destination_snapshot['attempt_uuid'], 'fence' => 1], static function (): void {});
        $bytes = file_get_contents($path);
        $this->s3->disk->put('test-backups/'.$this->run->object_key, $bytes);
        $this->run->update(['size_bytes' => strlen($bytes), 'ciphertext_checksum' => hash('sha256', $bytes)]);
        $this->getJson($this->url())->assertStatus(409);
        $this->assertFileDoesNotExist($this->transport->temporaryPath);
    }

    public function test_location_scoped_permission_and_inactive_module_do_not_authorize_download(): void
    {
        $other = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $branch = Location::query()->create(['name' => 'Download fixture branch', 'type' => 'branch']);
        setPermissionsTeamId($branch->id);
        try {
            $other->givePermissionTo('backups.download');
            $this->actingAs($other)->getJson($this->url())->assertForbidden();
        } finally {
            setPermissionsTeamId(null);
        }
        Module::query()->where('code', 'backup_restore')->update(['is_active' => false]);
        $this->actingAs($this->actor)->getJson($this->url())->assertForbidden();
        $this->assertSame([], $this->s3->commands);
    }

    public function test_existing_named_file_readback_preserves_integrity_and_failure_cleanup(): void
    {
        $path = $this->backupTestRoot.'/legacy-readback.fhbk';
        $this->transport->download($this->run, $path, static function (): void {});
        $this->assertSame($this->bytes, file_get_contents($path));
        unlink($path);
        $this->s3->truncateReadback = true;
        try {
            $this->transport->download($this->run, $path, static function (): void {});
            $this->fail('Truncated named-file readback was accepted.');
        } catch (BackupFailure $failure) {
            $this->assertSame('checksum_mismatch', $failure->getMessage());
        }
        $this->assertFileDoesNotExist($path);
    }

    public function test_history_download_link_is_localized_and_hidden_from_view_only_users(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(BackupHistory::class)->assertSee(__('backup_restore.download_archive'))->assertSee($this->url(), false);
        }
        $other = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $other->givePermissionTo('backups.view');
        $this->actingAs($other);
        Livewire::test(BackupHistory::class)->assertDontSee(__('backup_restore.download_archive'));
    }

    private function url(): string
    {
        return route('backups.download', ['uuid' => $this->run->uuid]);
    }
}
