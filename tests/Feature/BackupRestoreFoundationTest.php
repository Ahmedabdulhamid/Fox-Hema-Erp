<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\BackupHistory;
use App\Modules\BackupRestore\Filament\Pages\BackupSettings;
use App\Modules\BackupRestore\Filament\Pages\R2Settings;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupPermissions;
use App\Modules\BackupRestore\Services\BackupSettingsStore;
use App\Modules\BackupRestore\Services\R2ConnectionTest;
use App\Modules\BackupRestore\Services\R2Storage;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class BackupRestoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedStorage = storage_path('framework/testing/backup-foundation-test-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($this->isolatedStorage);
        config([
            'filesystems.disks.local.root' => $this->isolatedStorage.'/private',
            'filesystems.disks.public.root' => $this->isolatedStorage.'/public',
        ]);
        app(ModuleRegistry::class)->register();
        Module::query()->where('code', 'backup_restore')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->isolatedStorage)) {
                $path = realpath($this->isolatedStorage);
                $root = realpath(storage_path('framework/testing'));
                if ($path !== false && $root !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR)
                    && str_starts_with(basename($path), 'backup-foundation-test-')) {
                    File::deleteDirectory($path);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_schema_and_permissions_are_registered(): void
    {
        $this->assertStringStartsWith('codex_public_ids_test_', DB::connection()->getDatabaseName());
        foreach (['backup_destinations', 'backup_policies', 'backup_runs', 'backup_restore_runs', 'backup_operation_leases'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertCount(8, Module::query()->where('code', 'backup_restore')->firstOrFail()->permissions);
        $this->assertTrue(Schema::hasColumns('backup_runs', ['idempotency_key', 'ciphertext_checksum', 'manifest_checksum', 'retain_until', 'legal_hold']));
        $this->assertTrue(Schema::hasColumns('backup_operation_leases', ['scope', 'owner_token', 'fencing_number', 'expires_at']));
        $this->assertDatabaseCount('backup_runs', 0);
        $this->assertFalse(Gate::forUser($this->actor)->allows('create', BackupRun::class));
    }

    public function test_credentials_are_encrypted_hidden_and_unchanged_by_blank_replacements(): void
    {
        $this->configureDestination();
        $row = BackupDestination::query()->firstOrFail();
        foreach (['access_key_id' => 'foundation-access-key', 'secret_access_key' => 'foundation-secret-value'] as $column => $secret) {
            $this->assertNotSame($secret, DB::table('backup_destinations')->value($column));
            $this->assertSame($secret, $row->{$column});
            $this->assertStringNotContainsString($secret, $row->toJson());
        }
        $ciphertext = $row->getRawOriginal('secret_access_key');
        app(BackupSettingsStore::class)->saveDestination($this->actor, $this->destinationData(), 1, '', '');
        $this->assertSame($ciphertext, $row->fresh()->getRawOriginal('secret_access_key'));
        $this->assertSame(2, $row->fresh()->configuration_version);
        $this->assertNoSecrets(json_encode(app(BackupSettingsStore::class)->destination($this->actor), JSON_THROW_ON_ERROR));
    }

    public function test_livewire_hydration_html_and_audits_exclude_credentials(): void
    {
        $page = Livewire::test(R2Settings::class)->set('settings', $this->destinationData())
            ->call('save', 'foundation-access-key', 'foundation-secret-value')->assertHasNoErrors();
        $this->assertNoSecrets($page->html());
        $this->assertNoSecrets(json_encode($page->snapshot, JSON_THROW_ON_ERROR));
        $page->call('$refresh')->assertSet('version', 1);
        $this->assertArrayNotHasKey('access_key_id', $page->get('settings'));
        $this->assertArrayNotHasKey('secret_access_key', $page->get('settings'));
        $this->assertNoSecrets(AuthEvent::query()->where('event', 'like', 'backups.%')->get()->toJson());
    }

    public function test_first_save_requires_both_credentials_and_stale_saves_are_rejected(): void
    {
        Livewire::test(R2Settings::class)->set('settings', $this->destinationData())->call('save')->assertHasErrors(['credentials']);
        $this->assertDatabaseCount('backup_destinations', 0);
        $this->configureDestination();
        Livewire::test(R2Settings::class)->call('save', 'only-one-key', '')->assertHasErrors(['credentials']);
        $this->expectException(ValidationException::class);
        app(BackupSettingsStore::class)->saveDestination($this->actor, $this->destinationData(), 0, '', '');
    }

    #[DataProvider('unsafeEndpoints')]
    public function test_unsafe_endpoints_are_rejected_before_network_access(string $endpoint): void
    {
        $this->mock(R2Storage::class)->shouldNotReceive('probe');
        Livewire::test(R2Settings::class)->set('settings', array_merge($this->destinationData(), ['endpoint' => $endpoint]))
            ->call('save', 'foundation-access-key', 'foundation-secret-value')->assertHasErrors(['endpoint']);
        $this->assertDatabaseCount('backup_destinations', 0);
    }

    public static function unsafeEndpoints(): array
    {
        return array_map(fn (string $value): array => [$value], [
            'http://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'https://127.0.0.1',
            'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com.evil.test',
            'https://user:password@'.str_repeat('a', 32).'.r2.cloudflarestorage.com',
            'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com:443',
            'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com/path',
            'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com?query=1',
        ]);
    }

    public function test_connection_failure_does_not_expose_or_log_sdk_errors(): void
    {
        $this->configureDestination();
        Log::spy();
        $this->mock(R2Storage::class)->shouldReceive('probe')->once()->andThrow(new RuntimeException('signed request foundation-access-key foundation-secret-value'));
        Livewire::test(R2Settings::class)->call('testConnection')->assertHasNoErrors();
        $this->assertDatabaseHas('backup_destinations', ['probe_status' => 'failed']);
        $this->assertNoSecrets(AuthEvent::query()->get()->toJson());
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertDatabaseCount('backup_runs', 0);
    }

    public function test_connection_success_resets_on_configuration_change(): void
    {
        $this->configureDestination();
        $this->mock(R2Storage::class)->shouldReceive('probe')->once();
        $this->assertTrue(app(R2ConnectionTest::class)->check($this->actor));
        $this->assertDatabaseHas('backup_destinations', ['probe_status' => 'passed']);
        app(BackupSettingsStore::class)->saveDestination($this->actor, $this->destinationData(), 1, '', '');
        $this->assertDatabaseHas('backup_destinations', ['probe_status' => 'not_checked', 'last_checked_at' => null]);
    }

    public function test_destination_identity_changes_preserve_historical_location(): void
    {
        $this->configureDestination();
        $original = BackupDestination::query()->firstOrFail();
        $run = BackupRun::factory()->create(['destination_id' => $original->id]);
        app(BackupSettingsStore::class)->saveDestination($this->actor, array_merge($this->destinationData(), ['bucket' => 'new-private-bucket']), 1, '', '');
        $this->assertSame('retired', $original->fresh()->status);
        $this->assertSame($original->id, $run->fresh()->destination_id);
        $this->assertSame('foundation-secret-value', BackupDestination::query()->where('status', 'active')->firstOrFail()->secret_access_key);
    }

    public function test_global_grants_ignore_team_switching_and_branch_grants_never_authorize(): void
    {
        $branch = Location::query()->create(['name' => 'Backup test branch', 'code' => 'BKP', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true]);
        $user->locations()->attach($branch);
        $permission = Permission::query()->where('name', BackupPermissions::SETTINGS)->firstOrFail();
        $role = Role::query()->create(['name' => 'branch_backup_admin', 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo($permission);
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'location_id' => null]);
        DB::table('model_has_permissions')->insert(['permission_id' => $permission->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'location_id' => $branch->id]);
        foreach ([null, $branch->id] as $team) {
            setPermissionsTeamId($team);
            $this->assertFalse(app(BackupAccess::class)->allows($user, BackupPermissions::SETTINGS));
            $this->assertFalse($user->can(BackupPermissions::SETTINGS));
        }
        $role->update(['location_id' => null]);
        foreach ([null, $branch->id] as $team) {
            setPermissionsTeamId($team);
            $this->assertTrue(app(BackupAccess::class)->allows($user, BackupPermissions::SETTINGS));
            $this->assertTrue($user->can(BackupPermissions::SETTINGS));
            $this->assertFalse(app(BackupAccess::class)->allows($user, BackupPermissions::VIEW));
            $this->assertSame($team, getPermissionsTeamId());
        }
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertNull(app(LocationContext::class)->setFor($user, null));
        $user->update(['is_active' => false]);
        $user->forceFill(['is_active' => true]);
        $this->assertFalse(app(BackupAccess::class)->allows($user, BackupPermissions::SETTINGS));
        setPermissionsTeamId(null);
    }

    public function test_livewire_actions_reauthorize_after_module_deactivation(): void
    {
        $this->configureDestination();
        $page = Livewire::test(R2Settings::class);
        Module::query()->where('code', 'backup_restore')->update(['is_active' => false]);
        $this->mock(R2Storage::class)->shouldNotReceive('probe');
        $page->call('testConnection')->assertForbidden();
        $this->assertFalse(BackupHistory::canAccess());
        $this->assertFalse(BackupSettings::canAccess());
    }

    public function test_schedule_and_retention_configuration_remain_inactive_and_validate_input(): void
    {
        Livewire::test(BackupSettings::class)->set('settings.daily_time', '03:15')->set('recipients', 'owner@example.test')->call('save')->assertHasNoErrors();
        $policy = BackupPolicy::query()->findOrFail(1);
        $this->assertFalse($policy->enabled);
        $this->assertNull($policy->next_due_at);
        $this->assertSame(['owner@example.test'], $policy->notification_recipients);
        Livewire::test(BackupSettings::class)->set('settings.timezone', 'bad/timezone')->set('settings.daily_time', '25:90')
            ->set('settings.minimum_verified', 99)->set('recipients', 'not-an-email')->call('save')
            ->assertHasErrors(['timezone', 'daily_time', 'minimum_verified', 'notification_recipients.0']);
        $this->assertDatabaseCount('backup_policies', 1);
        $this->assertDatabaseCount('backup_runs', 0);
    }

    public function test_history_is_read_only_paginated_and_localized(): void
    {
        $this->configureDestination();
        BackupRun::factory()->count(21)->create(['destination_id' => BackupDestination::query()->value('id')]);
        Livewire::test(BackupHistory::class)->assertSee('Backup History')->assertSee('Queued')->assertDontSee('foundation-secret-value')
            ->set('status', 'failed')->assertSee('No backup history yet.');
        app()->setLocale('ar');
        Livewire::test(BackupHistory::class)->assertSee('سجل النسخ الاحتياطي')->assertSee('في الانتظار')->assertSeeHtml('dir="rtl"');
        $this->assertFalse(method_exists(BackupHistory::class, 'createBackup'));
        $this->assertSame(array_keys(require lang_path('en/backup_restore.php')), array_keys(require lang_path('ar/backup_restore.php')));
    }

    public function test_history_uses_light_idle_polling_and_refreshes_for_active_operations(): void
    {
        $this->configureDestination();
        $page = Livewire::test(BackupHistory::class)
            ->assertActionExists('refreshHistory')
            ->assertSee(__('backup_restore.refresh'))
            ->assertSeeHtml('wire:poll.1m.visible="refreshStatus"');

        $page->call('refreshStatus')->assertSeeHtml('wire:poll.1m.visible="refreshStatus"');

        $run = BackupRun::factory()->create(['destination_id' => BackupDestination::query()->value('id')]);
        $page->call('refreshStatus')->assertSeeHtml('wire:poll.10s.visible="refreshStatus"');

        DB::table('backup_runs')->where('id', $run->id)->update(['status' => 'cancelled']);
        $page->call('refreshStatus')->assertSeeHtml('wire:poll.1m.visible="refreshStatus"')
            ->assertSee(__('backup_restore.cancelled'));

        $verifiedBackup = BackupRun::factory()->create(['destination_id' => BackupDestination::query()->value('id'), 'status' => 'available',
            'verification_status' => 'checksum_verified', 'verified_at' => now(), 'completed_at' => now(), 'object_key' => 'fixtures/verified.fhbk',
            'size_bytes' => 1, 'ciphertext_checksum' => str_repeat('a', 64), 'manifest_checksum' => str_repeat('b', 64)]);
        BackupRestoreRun::factory()->create(['backup_run_id' => $verifiedBackup->id, 'status' => 'verified']);
        $page->call('refreshStatus')->assertSeeHtml('wire:poll.1m.visible="refreshStatus"');

        BackupRestoreRun::factory()->create(['backup_run_id' => $verifiedBackup->id, 'status' => 'verifying']);
        $page->call('refreshStatus')->assertSeeHtml('wire:poll.10s.visible="refreshStatus"');

        $page->call('refreshHistory')->assertHasNoErrors();
    }

    public function test_policy_singleton_constraint_is_enforced(): void
    {
        $this->expectException(QueryException::class);
        DB::table('backup_policies')->insert(['id' => 2]);
    }

    public function test_available_backup_requires_integrity_evidence(): void
    {
        $this->configureDestination();
        $this->expectException(QueryException::class);
        BackupRun::factory()->create(['destination_id' => BackupDestination::query()->value('id'), 'status' => 'available']);
    }

    public function test_audit_sanitizer_removes_nested_credential_fields(): void
    {
        $event = app(AuditLogger::class)->record('backups.sanitizer_test', metadata: ['nested' => [
            'access_key_id' => 'foundation-access-key', 'secret_access_key' => 'foundation-secret-value', 'credentials' => ['key' => 'foundation-secret-value'],
        ]]);
        $this->assertNoSecrets($event->toJson());
    }

    public function test_runtime_adapter_is_private_bounded_and_does_not_change_existing_disks(): void
    {
        $this->configureDestination();
        $before = config('filesystems');
        $disk = app(R2Storage::class)->disk(BackupDestination::query()->firstOrFail());
        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $this->assertSame('private', $disk->getConfig()['visibility']);
        $this->assertSame('auto', $disk->getClient()->getRegion());
        $this->assertSame(0, $disk->getConfig()['retries']);
        $this->assertFalse($disk->getConfig()['debug']);
        $this->assertFalse($disk->getConfig()['http']['allow_redirects']);
        $this->assertSame(8, $disk->getConfig()['http']['timeout']);
        $this->assertSame($before, config('filesystems'));
    }

    public function test_policy_saved_before_r2_configuration_attaches_to_the_first_destination(): void
    {
        Livewire::test(BackupSettings::class)->call('save')->assertHasNoErrors();
        $this->assertNull(BackupPolicy::query()->findOrFail(1)->destination_id);
        $this->configureDestination();
        $this->assertSame(BackupDestination::query()->value('id'), BackupPolicy::query()->findOrFail(1)->destination_id);
        $this->assertFalse(BackupPolicy::query()->findOrFail(1)->enabled);
    }

    public function test_view_only_global_permission_cannot_save_settings_or_test_connections(): void
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $permission = Permission::query()->where('name', BackupPermissions::VIEW)->firstOrFail();
        DB::table('model_has_permissions')->insert(['permission_id' => $permission->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'location_id' => null]);
        $this->actingAs($user);
        $this->assertTrue(BackupHistory::canAccess());
        $this->assertFalse(R2Settings::canAccess());
        $this->assertFalse(BackupSettings::canAccess());
        $this->mock(R2Storage::class)->shouldNotReceive('probe');
        $this->expectException(HttpException::class);
        app(R2ConnectionTest::class)->check($user);
    }

    public function test_run_idempotency_and_historical_foreign_keys_are_enforced(): void
    {
        $this->configureDestination();
        $run = BackupRun::factory()->create(['destination_id' => BackupDestination::query()->value('id')]);
        try {
            BackupRun::factory()->create(['destination_id' => $run->destination_id, 'idempotency_key' => $run->idempotency_key]);
            $this->fail('Duplicate idempotency key was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('backup_runs', 1);
        }
        $this->expectException(QueryException::class);
        BackupDestination::query()->whereKey($run->destination_id)->delete();
    }

    private function configureDestination(): void
    {
        app(BackupSettingsStore::class)->saveDestination($this->actor, $this->destinationData(), 0, 'foundation-access-key', 'foundation-secret-value');
    }

    private function destinationData(): array
    {
        return ['name' => 'Cloudflare R2', 'endpoint' => 'https://'.str_repeat('a', 32).'.r2.cloudflarestorage.com', 'bucket' => 'private-backup-bucket', 'prefix' => 'erp-backups'];
    }

    private function assertNoSecrets(string $value): void
    {
        $this->assertStringNotContainsString('foundation-access-key', $value);
        $this->assertStringNotContainsString('foundation-secret-value', $value);
    }
}
