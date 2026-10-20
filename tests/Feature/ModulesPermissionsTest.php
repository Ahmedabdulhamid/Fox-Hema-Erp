<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Modules\ModuleResource;
use App\Modules\AccessManagement\Filament\Resources\Modules\Pages\EditModule;
use App\Modules\AccessManagement\Filament\Resources\Permissions\PermissionResource;
use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\EditRole;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\AccessManagement\Services\PermissionGroups;
use App\Modules\AccessManagement\Services\RoleAccessManager;
use App\Modules\AccessManagement\Services\SuperAdminProvisioner;
use App\Modules\AccessManagement\Services\UserAccessManager;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Products\Services\ProductPermissions;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Sales\Services\SalesPermissions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ModulesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function admin(): User
    {
        $user = $this->provisionTestSuperAdmin();
        $user->forceFill(['must_change_password' => false])->save();
        $this->actingAs($user);

        return $user;
    }

    private function scoped(array $permissions): array
    {
        $location = Location::create(['name' => 'Branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return [$user->fresh(), $location, $role];
    }

    public function test_registration_preserves_users_historical_permissions_and_module_settings(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $before = $user->refresh()->getAttributes();
        $module = Module::where('code', 'products')->firstOrFail();
        $module->update(['sort_order' => 543, 'is_active' => true, 'name' => 'Catalog']);
        $historical = Permission::create(['name' => 'legacy.export', 'guard_name' => 'web']);
        $api = Permission::create(['name' => 'api.read', 'guard_name' => 'api']);
        $this->seed(AccessManagementSeeder::class);
        $this->seed(AccessManagementSeeder::class);
        $this->assertEqualsCanonicalizing(array_keys(ModuleRegistry::MODULES), Module::pluck('code')->all());
        $this->assertSame(543, $module->refresh()->sort_order);
        $this->assertSame('Catalog', $module->name);
        $this->assertTrue($module->is_active);
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertSame(count(app(ModuleRegistry::class)->implementedPermissions()), DB::table('modules_permissions')->count());
        $super = Role::where('name', 'super_admin')->whereNull('location_id')->firstOrFail();
        $this->assertTrue($super->permissions()->whereKey($historical)->exists());
        $this->assertFalse($super->permissions()->whereKey($api)->exists());
        $this->assertSame(1 + count(app(ModuleRegistry::class)->implementedPermissions()), $super->permissions()->count());
        $this->assertSame(0, User::all()->filter->isGlobalSuperAdmin()->count());
    }

    public function test_implemented_permissions_map_to_implemented_modules_only(): void
    {
        $module = Module::where('code', 'access_management')->firstOrFail();
        $this->assertEqualsCanonicalizing(AccessManagementSeeder::PERMISSIONS, $module->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(ProductPermissions::all(), Module::where('code', 'products')->firstOrFail()->permissions->pluck('name')->all());
        foreach (['taxes', 'discounts'] as $code) {
            $this->assertEqualsCanonicalizing(array_map(fn (string $operation): string => $code.'.'.$operation, ['view', 'create', 'update', 'delete', 'assign']), Module::where('code', $code)->firstOrFail()->permissions->pluck('name')->all());
        }
        $this->assertEqualsCanonicalizing(InventoryPermissions::all(), Module::where('code', 'inventory')->firstOrFail()->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(PurchasingPermissions::all(), Module::where('code', 'purchasing')->firstOrFail()->permissions->pluck('name')->all());
        $this->assertEqualsCanonicalizing(SalesPermissions::all(), Module::where('code', 'sales')->firstOrFail()->permissions->pluck('name')->all());
        $this->assertSame(0, Module::whereNotIn('code', ['access_management', 'products', 'taxes', 'discounts', 'inventory', 'purchasing', 'sales'])->get()->sum(fn (Module $module) => $module->permissions()->count()));
        $this->assertSame(10, Module::where('is_active', false)->count());
    }

    public function test_registration_restores_location_context_and_maps_future_real_permissions(): void
    {
        [$user, $location] = $this->scoped(['users.view']);
        $permission = Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']);
        app(ModuleRegistry::class)->register();
        $this->assertSame($location->id, getPermissionsTeamId());
        $this->assertTrue(Module::where('code', 'products')->firstOrFail()->permissions()->whereKey($permission)->exists());
        $this->assertTrue(Role::whereNull('location_id')->where('name', 'super_admin')->firstOrFail()->permissions()->whereKey($permission)->exists());
    }

    public function test_authorized_pages_and_read_only_permissions_render(): void
    {
        $admin = $this->admin();
        $module = Module::where('code', 'access_management')->firstOrFail();
        $permission = Permission::where('name', 'users.view')->firstOrFail();
        foreach (['/admin/modules', '/admin/modules/'.$module->getRouteKey(), '/admin/modules/'.$module->getRouteKey().'/edit', '/admin/permissions', '/admin/permissions/'.$permission->getRouteKey()] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertFalse(PermissionResource::canCreate());
        $this->assertFalse(PermissionResource::canEdit($permission));
        $this->assertFalse(PermissionResource::canDelete($permission));
        $this->assertFalse(ModuleResource::canCreate());
        $this->assertFalse(ModuleResource::canDelete($module));
    }

    public function test_direct_urls_and_navigation_require_permissions(): void
    {
        [$user] = $this->scoped(['users.view']);
        $this->get('/admin/modules')->assertForbidden();
        $this->get('/admin/permissions')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();
        app(LocationContext::class)->initializeFor($user);
        $this->assertFalse(ModuleResource::canAccess());
        $this->assertFalse(PermissionResource::canAccess());
        $this->get('/admin/users')->assertOk()->assertDontSee(ModuleResource::getUrl(), false)->assertDontSee(PermissionResource::getUrl(), false);
    }

    public function test_module_view_permission_grants_page_access_only_in_its_location(): void
    {
        [$user, $first] = $this->scoped(['modules.view', 'permissions.view']);
        $this->get('/admin/modules')->assertOk();
        $this->get('/admin/permissions')->assertOk();
        $second = Location::create(['name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $user->locations()->attach($second);
        app(LocationContext::class)->setFor($user, $second->id);
        $this->assertFalse($user->can('modules.view'));
        $this->assertFalse(ModuleResource::canViewAny());
        app(LocationContext::class)->setFor($user, $first->id);
        $this->assertTrue(ModuleResource::canViewAny());
    }

    public function test_module_settings_save_and_access_management_cannot_be_disabled(): void
    {
        $this->admin();
        $module = Module::where('code', 'products')->firstOrFail();
        Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])->fillForm(['is_active' => true, 'sort_order' => 7])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($module->refresh()->is_active);
        $this->assertSame(7, $module->sort_order);
        $this->expectException(ValidationException::class);
        Module::where('code', 'access_management')->firstOrFail()->update(['is_active' => false]);
    }

    public function test_module_viewer_cannot_mutate_modules_via_livewire(): void
    {
        $this->scoped(['modules.view']);
        $module = Module::where('code', 'products')->firstOrFail();
        Livewire::test(EditModule::class, ['record' => $module->getRouteKey()])->assertForbidden();
        $this->assertFalse($module->refresh()->is_active);
    }

    public function test_inactive_business_module_denies_gates_services_and_direct_routes_even_for_super_admin(): void
    {
        $admin = $this->admin();
        $permission = Permission::firstOrCreate(['name' => 'products.view', 'guard_name' => 'web']);
        app(ModuleRegistry::class)->register();
        Route::middleware(['web', 'auth', 'module.active:products'])->get('/module-access-test', fn () => 'ok');
        $this->get('/module-access-test')->assertForbidden();
        $this->assertFalse($admin->can('products.view'));
        try {
            app(ModuleAccess::class)->authorize($admin, 'products', 'products.view');
            $this->fail('Inactive module service access was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Module::where('code', 'products')->firstOrFail()->update(['is_active' => true]);
        $this->get('/module-access-test')->assertOk();
        app(ModuleAccess::class)->authorize($admin, 'products', 'products.view');
    }

    public function test_existing_user_provisioning_is_explicit_idempotent_and_preserves_credentials(): void
    {
        $user = User::factory()->create(['email' => 'target@example.test', 'is_active' => true]);
        $before = $user->refresh()->getAttributes();
        $this->artisan('access:provision-super-admin', ['email' => $user->email])->assertFailed();
        $this->assertFalse($user->isGlobalSuperAdmin());
        $this->artisan('access:provision-super-admin', ['email' => $user->email, '--assign-existing' => true])->assertSuccessful();
        $this->artisan('access:provision-super-admin', ['email' => $user->email, '--assign-existing' => true])->assertSuccessful();
        $this->assertSame($before, $user->refresh()->getAttributes());
        $this->assertTrue($user->isGlobalSuperAdmin());
        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $user->id)->whereNull('location_id')->count());
    }

    public function test_new_super_admin_command_uses_hidden_confirmed_password(): void
    {
        $this->artisan('access:provision-super-admin', ['email' => 'new-admin@example.test'])
            ->expectsQuestion('Name', 'New Admin')
            ->expectsQuestion('Password (at least 12 characters)', 'a-secure-test-password')
            ->expectsQuestion('Confirm password', 'a-secure-test-password')->assertSuccessful();
        $user = User::where('email', 'new-admin@example.test')->firstOrFail();
        $this->assertTrue($user->isGlobalSuperAdmin());
        $this->assertTrue($user->must_change_password);
        $this->assertNotSame('a-secure-test-password', $user->password);
    }

    public function test_invalid_missing_and_inactive_provisioning_targets_are_rejected(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->artisan('access:provision-super-admin', ['email' => $user->email, '--assign-existing' => true])->assertFailed();
        $this->artisan('access:provision-super-admin', ['email' => 'missing@example.test', '--assign-existing' => true])->assertFailed();
        $this->artisan('access:provision-super-admin', ['email' => 'invalid', '--assign-existing' => true])->assertFailed();
        $this->assertFalse($user->refresh()->is_active);
        $this->assertFalse($user->isGlobalSuperAdmin());
    }

    public function test_ordinary_user_cannot_delegate_without_permission_assignment_authority(): void
    {
        [$actor, $location] = $this->scoped(['users.view', 'users.update', 'roles.view']);
        $target = User::factory()->create();
        $target->locations()->attach($location);
        $permission = Permission::where('name', 'roles.view')->firstOrFail();
        $this->expectException(HttpException::class);
        app(UserAccessManager::class)->sync($target->fresh(), $location->id, [], [$permission->id]);
    }

    public function test_self_assignment_and_unowned_permission_delegation_are_rejected(): void
    {
        [$actor, $location] = $this->scoped(['users.view', 'users.update', 'permissions.assign']);
        $target = User::factory()->create();
        $target->locations()->attach($location);
        $permission = Permission::where('name', 'roles.delete')->firstOrFail();
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->fillForm(['permission_ids' => [$permission->id]])->call('save')->assertHasFormErrors();
        $this->assertSame([], app(UserAccessManager::class)->assignments($target, $location->id)['permission_ids']);
        $owned = Permission::where('name', 'users.view')->firstOrFail();
        Livewire::test(EditUser::class, ['record' => $actor->getRouteKey()])->fillForm(['permission_ids' => [$owned->id]])->call('save')->assertForbidden();
    }

    public function test_role_scope_tampering_and_reserved_role_assignment_are_rejected(): void
    {
        [$actor, $location, $role] = $this->scoped(['roles.view', 'roles.update', 'permissions.assign', 'users.view', 'users.update']);
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->set('data.scope', 'global')->call('save')->assertHasNoFormErrors();
        $this->assertSame($location->id, $role->refresh()->location_id);
        try {
            app(RoleAccessManager::class)->save($actor, ['name' => $role->name, 'scope' => 'global', 'permission_ids' => $role->permissions->pluck('id')->all()], $role);
            $this->fail('Scope escalation was allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $super = Role::where('name', 'super_admin')->whereNull('location_id')->firstOrFail();
        $target = User::factory()->create();
        $target->locations()->attach($location);
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->set('data.role_ids', [$super->id])->call('save')->assertHasFormErrors();
        $this->assertFalse($target->isGlobalSuperAdmin());
    }

    public function test_assigned_roles_cannot_move_scope_even_for_super_admin(): void
    {
        [$actor, $location, $role] = $this->scoped(['users.view']);
        $admin = $this->admin();
        $this->expectException(ValidationException::class);
        app(RoleAccessManager::class)->save($admin, ['name' => $role->name, 'scope' => 'global', 'permission_ids' => $role->permissions->pluck('id')->all()], $role);
    }

    public function test_last_active_super_admin_cannot_be_disabled_deleted_or_demoted(): void
    {
        $admin = $this->admin();
        foreach (['disable', 'delete', 'demote', 'sync_roles', 'remove_role'] as $action) {
            try {
                if ($action === 'disable') {
                    $admin->update(['is_active' => false]);
                } elseif ($action === 'delete') {
                    $admin->delete();
                } elseif ($action === 'sync_roles') {
                    $admin->syncRoles([]);
                } elseif ($action === 'remove_role') {
                    $admin->removeRole(Role::whereNull('location_id')->where('name', 'super_admin')->firstOrFail());
                } else {
                    app(UserAccessManager::class)->sync($admin->fresh(), null, [], []);
                }
                $this->fail('Last administrator removal was allowed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('data.is_active', $exception->errors());
                $admin->refresh();
            }
        }
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isGlobalSuperAdmin());
    }

    public function test_same_role_name_in_wrong_guard_or_location_never_grants_super_admin_access(): void
    {
        [$user, $location] = $this->scoped(['users.view']);
        $role = new Role(['name' => 'super_admin', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->save();
        $user->assignRole($role);
        $this->assertFalse($user->isGlobalSuperAdmin());
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'api', 'location_id' => null]);
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_id' => $user->id, 'model_type' => $user->getMorphClass(), 'location_id' => null]);
        $this->assertFalse($user->isGlobalSuperAdmin());
    }

    public function test_permission_options_are_grouped_by_module_and_preserve_historical_entries(): void
    {
        $admin = $this->admin();
        $historical = Permission::create(['name' => 'legacy.read', 'guard_name' => 'web']);
        $groups = app(PermissionGroups::class)->options($admin);
        $this->assertContains('users.view', $groups['AccessManagement']);
        $this->assertSame('legacy.read', $groups['Unmapped historical permissions'][$historical->id]);
    }

    public function test_backend_rejects_cross_location_role_creation_and_unowned_permissions(): void
    {
        [$actor, $location] = $this->scoped(['roles.create', 'permissions.assign', 'users.view']);
        $foreign = Location::create(['name' => 'Foreign', 'type' => 'branch', 'is_active' => true]);
        $privileged = Permission::where('name', 'roles.delete')->firstOrFail();
        foreach ([['location_id' => $foreign->id, 'permission_ids' => []], ['location_id' => $location->id, 'permission_ids' => [$privileged->id]], ['location_id' => $location->id, 'permission_ids' => [99999]]] as $data) {
            try {
                app(RoleAccessManager::class)->save($actor, $data + ['name' => 'rejected', 'scope' => 'location']);
                $this->fail('Unauthorized role was created.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseMissing('roles', ['name' => 'rejected']);
    }

    public function test_backend_rejects_unowned_user_permissions_even_when_form_validation_is_bypassed(): void
    {
        [$actor, $location] = $this->scoped(['users.view', 'users.update', 'permissions.assign']);
        $target = User::factory()->create();
        $target->locations()->attach($location);
        $permission = Permission::where('name', 'roles.delete')->firstOrFail();
        $this->expectException(HttpException::class);
        app(UserAccessManager::class)->sync($target->fresh(), $location->id, [], [$permission->id]);
    }

    public function test_user_delete_requires_delete_permission_and_valid_scope(): void
    {
        [$actor, $location] = $this->scoped(['users.view', 'users.update']);
        $target = User::factory()->create();
        $target->locations()->attach($location);
        $this->assertFalse($actor->can('delete', $target));
        $actor->givePermissionTo('users.delete');
        $this->assertTrue($actor->can('delete', $target));
        $this->assertFalse($actor->can('delete', User::factory()->create()));
    }

    public function test_a_second_active_super_admin_allows_disabling_one_but_protects_the_remaining_one(): void
    {
        $first = $this->admin();
        $second = app(SuperAdminProvisioner::class)->provision('second@example.test', false, 'Second', 'another-secure-password');
        $first->update(['is_active' => false]);
        $this->assertFalse($first->refresh()->is_active);
        $this->expectException(ValidationException::class);
        $second->update(['is_active' => false]);
    }

    public function test_provisioning_restores_team_context_and_does_not_restore_deleted_users(): void
    {
        [$actor, $location] = $this->scoped(['users.view']);
        $target = User::factory()->create(['is_active' => true]);
        $target->delete();
        $this->artisan('access:provision-super-admin', ['email' => $target->email, '--assign-existing' => true])->assertFailed();
        $this->assertSoftDeleted($target);
        $this->assertSame($location->id, getPermissionsTeamId());
        app(SuperAdminProvisioner::class)->provision('context@example.test', false, 'Context', 'another-secure-password');
        $this->assertSame($location->id, getPermissionsTeamId());
    }

    public function test_password_confirmation_failure_does_not_create_a_user(): void
    {
        $this->artisan('access:provision-super-admin', ['email' => 'mismatch@example.test', '--name' => 'Mismatch'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-secure-test-password')
            ->expectsQuestion('Confirm password', 'a-different-password')->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }

    public function test_real_livewire_update_retains_location_scope_and_clears_it_after_the_request(): void
    {
        [$actor, $location] = $this->scoped(['users.view', 'users.update']);
        $target = User::factory()->create();
        $target->locations()->attach($location);
        $response = $this->get('/admin/users/'.$target->getRouteKey().'/edit')->assertOk();
        $this->assertNull(getPermissionsTeamId());
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = null;
        foreach ($matches[1] as $encoded) {
            $candidate = html_entity_decode($encoded, ENT_QUOTES);
            if (str_contains(strtolower($candidate), 'edituser') || str_contains($candidate, 'edit-user')) {
                $snapshot = $candidate;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        $uri = app(HandleRequests::class)->getUpdateUri();
        $this->postJson($uri, ['components' => [[
            'snapshot' => $snapshot, 'updates' => ['data.name' => 'Updated within branch'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $this->assertSame('Updated within branch', $target->refresh()->name);
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_module_prefix_mapping_treats_underscores_literally(): void
    {
        $right = Permission::create(['name' => 'stock_transfers.view', 'guard_name' => 'web']);
        $wrong = Permission::create(['name' => 'stockXtransfers.view', 'guard_name' => 'web']);
        app(ModuleRegistry::class)->register();
        $module = Module::where('code', 'stock_transfers')->firstOrFail();
        $this->assertTrue($module->permissions()->whereKey($right)->exists());
        $this->assertFalse($module->permissions()->whereKey($wrong)->exists());
    }
}
