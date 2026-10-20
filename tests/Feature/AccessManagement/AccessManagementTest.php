<?php

namespace Tests\Feature\AccessManagement;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Policies\RolePolicy;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\SessionManager;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('access-management.seed_admin', [
            'name' => 'Super Admin',
            'email' => 'admin@example.test',
            'password' => 'a-secure-test-password',
        ]);
    }

    public function test_registration_and_explicit_provisioning_are_idempotent(): void
    {
        $location = Location::create(['name' => 'Main Branch', 'code' => 'MAIN', 'type' => 'branch', 'is_active' => true]);
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();

        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->isGlobalSuperAdmin());
        $this->assertSame(1, User::where('email', $user->email)->count());
        $this->assertSame(count(app(ModuleRegistry::class)->implementedPermissions()), Permission::count());
        $this->assertSame(1, Role::whereNull('location_id')->where('name', 'super_admin')->count());
        $this->assertSame(['cashier', 'stock_manager'], Role::where('location_id', $location->id)->orderBy('name')->pluck('name')->all());
    }

    public function test_seeder_preserves_existing_users_without_provisioning(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'is_active' => false]);
        $before = $user->refresh()->getAttributes();
        $this->seed(AccessManagementSeeder::class);
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertFalse($user->isGlobalSuperAdmin());
    }

    public function test_panel_access_requires_active_and_explicitly_authorized_user(): void
    {
        $panel = Filament::getPanel('admin');
        $inactive = User::factory()->create(['is_active' => false]);
        $unauthorized = User::factory()->create(['is_active' => true]);
        $this->assertFalse($inactive->canAccessPanel($panel));
        $this->assertFalse($unauthorized->canAccessPanel($panel));
    }

    public function test_global_super_admin_can_access_panel_and_system_role_is_protected(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $role = Role::where('name', 'super_admin')->whereNull('location_id')->firstOrFail();
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse(app(RolePolicy::class)->update($user, $role));
        $this->assertFalse(app(RolePolicy::class)->delete($user, $role));
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user)->get('/admin/roles')->assertOk();
    }

    public function test_location_roles_are_isolated_when_context_switches_and_cache_is_refreshed(): void
    {
        $permission = Permission::create(['name' => 'roles.view', 'guard_name' => 'web']);
        [$first, $second] = collect(['First', 'Second'])->map(fn ($name) => Location::create([
            'name' => $name, 'code' => strtoupper($name), 'type' => 'branch', 'is_active' => true,
        ]))->all();
        $user = User::factory()->create(['is_active' => true]);
        $user->locations()->attach([$first->id, $second->id]);

        app(LocationContext::class)->setFor($user, $first->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'web', 'location_id' => $first->id]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $this->assertTrue($user->fresh()->can('roles.view'));

        app(LocationContext::class)->setFor($user, $second->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($user->fresh()->can('roles.view'));
    }

    public function test_ordinary_manager_cannot_cross_locations_or_delegate_unowned_permissions(): void
    {
        $first = Location::create(['name' => 'First', 'code' => 'FIRST', 'type' => 'branch', 'is_active' => true]);
        $second = Location::create(['name' => 'Second', 'code' => 'SECOND', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create();
        $user->locations()->attach($first);
        app(LocationContext::class)->setFor($user, $first->id);
        foreach (['roles.view', 'roles.update'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $ownRole = Role::create(['name' => 'own-manager', 'guard_name' => 'web', 'location_id' => $first->id]);
        $ownRole->syncPermissions(['roles.view', 'roles.update']);
        $user->assignRole($ownRole);
        $foreignRole = Role::create(['name' => 'foreign', 'guard_name' => 'web', 'location_id' => $second->id]);

        $policy = app(RolePolicy::class);
        $this->assertTrue($policy->update($user->fresh(), $ownRole));
        $this->assertFalse($policy->update($user, $foreignRole));
        $this->assertFalse($policy->assignPermissions($user, $ownRole));
    }

    public function test_unauthorized_location_switch_is_rejected(): void
    {
        $location = Location::create(['name' => 'Other', 'code' => 'OTHER', 'type' => 'warehouse', 'is_active' => true]);
        $user = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(LocationContext::class)->setFor($user, $location->id);
    }

    public function test_auth_provider_and_password_reset_use_module_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $user->email]));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forced_password_change_blocks_admin_pages_but_allows_change_form(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();

        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)->get('/admin/roles')->assertRedirect(route('access-management.password.edit'));
        $this->actingAs($user)->get(route('access-management.password.edit'))->assertOk();
    }

    public function test_session_manager_deletes_real_database_session(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'kept-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'remote-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->assertSame(1, app(SessionManager::class)->revokeAll($user, 'kept-session'));
        $this->assertDatabaseHas('sessions', ['id' => 'kept-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'remote-session']);
    }

    public function test_session_manager_can_revoke_orphaned_session_without_touching_owned_sessions(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'orphan-session', 'user_id' => null, 'payload' => '', 'last_activity' => time()],
            ['id' => 'owned-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->assertTrue(app(SessionManager::class)->revoke('orphan-session', null));
        $this->assertDatabaseMissing('sessions', ['id' => 'orphan-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'owned-session', 'user_id' => $user->id]);
        $this->assertDatabaseHas('auth_events', [
            'event' => 'session_revoked',
            'actor_user_id' => null,
            'target_user_id' => null,
        ]);
    }

    public function test_audit_logger_strips_secrets_and_events_are_append_only(): void
    {
        $user = User::factory()->create();
        $event = app(AuditLogger::class)->record('security_test', $user, [
            'safe' => 'value', 'password' => 'secret', 'nested' => ['token' => 'secret'],
        ]);

        $this->assertSame(['safe' => 'value', 'nested' => []], $event->metadata);
        $event->event = 'tampered';
        $this->assertFalse($event->save());
        $this->assertSame('security_test', AuthEvent::findOrFail($event->id)->event);
    }

    public function test_super_admin_can_render_all_access_management_resources(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();

        foreach (['/admin', '/admin/profile', '/admin/users', '/admin/users/create', '/admin/locations', '/admin/locations/create', '/admin/roles', '/admin/roles/create', '/admin/security-events/auth-events', '/admin/sessions/database-sessions'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_auth_event_detail_resolves_location_metadata_to_location_name(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();
        $location = Location::create([
            'name' => 'Detail Branch',
            'code' => 'DETAIL',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $event = AuthEvent::query()->create([
            'actor_user_id' => $user->id,
            'target_user_id' => $user->id,
            'event' => 'location_created',
            'location_id' => $location->id,
            'metadata' => ['location_id' => $location->id],
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/admin/security-events/auth-events/'.$event->getRouteKey())
            ->assertOk()
            ->assertSee('Detail Branch')
            ->assertDontSee('location_id</th>', false);
    }

    public function test_role_permission_matrix_state_creates_a_scoped_role(): void
    {
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $user = User::where('email', 'admin@example.test')->firstOrFail();
        $user->forceFill(['must_change_password' => false])->save();
        $location = Location::create(['name' => 'Matrix Branch', 'code' => 'MATRIX', 'type' => 'branch', 'is_active' => true]);
        $permissionIds = Permission::query()->whereIn('name', ['users.view', 'roles.view'])->pluck('id')->all();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($user)->test(CreateRole::class)
            ->fillForm(['name' => 'matrix_manager', 'scope' => 'location', 'location_id' => $location->id, 'permission_ids' => $permissionIds])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'matrix_manager')->where('location_id', $location->id)->firstOrFail();
        $this->assertEqualsCanonicalizing($permissionIds, $role->permissions()->pluck('permissions.id')->all());
    }
}
