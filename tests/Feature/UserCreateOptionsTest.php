<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\PermissionGroups;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ViewField;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UserCreateOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('access-management.seed_admin', [
            'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'a-secure-test-password',
        ]);
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $this->admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_role_and_permission_created_from_selects_are_selected_and_saved_with_the_user(): void
    {
        $page = Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'New user', 'email' => 'new@example.test', 'password' => 'a-secure-test-password', 'password_confirmation' => 'a-secure-test-password']);

        $page->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'custom_manager'])->assertHasNoFormErrors();
        $role = Role::where('name', 'custom_manager')->firstOrFail();
        $this->assertNull($role->location_id);
        $this->assertSame('web', $role->guard_name);
        $page->assertFormSet(['role_ids' => [$role->id]]);

        $permission = Permission::where('name', 'users.view')->firstOrFail();
        $page->fillForm(['permission_ids' => [$permission->id]])->call('create')->assertHasNoFormErrors();
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($user->roles()->whereKey($role)->exists());
        $this->assertTrue($user->permissions()->whereKey($permission)->exists());
        $this->assertFalse($user->isGlobalSuperAdmin());
    }

    public function test_role_options_use_the_current_location_and_preserve_existing_selections(): void
    {
        $location = Location::create(['name' => 'Main', 'type' => 'branch', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->admin, $location->id);
        $existing = Role::create(['name' => 'existing', 'guard_name' => 'web', 'location_id' => $location->id]);
        $user = User::factory()->create();
        $user->locations()->attach($location);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->fillForm(['role_ids' => [$existing->id]])
            ->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'new_role'])
            ->assertHasNoFormErrors()->assertFormSet(['role_ids' => [$existing->id, Role::where('name', 'new_role')->firstOrFail()->id]]);
        $this->assertDatabaseHas('roles', ['name' => 'new_role', 'guard_name' => 'web', 'location_id' => $location->id]);
    }

    public function test_option_forms_reject_duplicate_names_and_reserved_system_roles(): void
    {
        Role::create(['name' => 'existing', 'guard_name' => 'web', 'location_id' => null]);
        Livewire::test(CreateUser::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'existing'])
            ->assertHasFormErrors(['name' => 'unique']);
        Livewire::test(CreateUser::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'super_admin'])
            ->assertHasFormErrors(['name']);
        $this->assertSame(1, Permission::where('name', 'users.view')->where('guard_name', 'web')->count());
    }

    public function test_scoped_role_creator_can_create_roles_but_cannot_create_permission_definitions(): void
    {
        $location = Location::create(['name' => 'Main', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $role = Role::create(['name' => 'creator', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->syncPermissions(['users.view', 'users.create', 'roles.create', 'permissions.assign']);
        $actor->assignRole($role);
        $this->actingAs($actor);

        Livewire::test(CreateUser::class)
            ->fillForm(['location_ids' => [$location->id]])
            ->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'scoped_role'])->assertHasNoFormErrors();
        $this->assertDatabaseHas('roles', ['name' => 'scoped_role', 'guard_name' => 'web', 'location_id' => $location->id]);

        $role->revokePermissionTo('roles.create');
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(CreateUser::class)->assertActionHidden(TestAction::make('createOption')->schemaComponent('role_ids'));
    }

    public function test_selecting_the_system_super_admin_role_cannot_create_a_privileged_user(): void
    {
        $role = Role::where('name', 'super_admin')->firstOrFail();
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Unauthorized admin', 'email' => 'privileged@example.test',
            'password' => 'a-secure-test-password', 'password_confirmation' => 'a-secure-test-password', 'role_ids' => [$role->id],
        ])->call('create')->assertHasFormErrors();

        $this->assertDatabaseMissing('users', ['email' => 'privileged@example.test']);
    }

    public function test_direct_permission_matrix_creates_loads_and_removes_only_direct_assignments_in_the_selected_scope(): void
    {
        $location = Location::create(['name' => 'Matrix branch', 'type' => 'branch', 'is_active' => true]);
        $other = Location::create(['name' => 'Other matrix branch', 'type' => 'branch', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->admin, $location->id);
        $role = Role::create(['name' => 'Matrix reader', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo('roles.view');
        $view = Permission::findByName('users.view')->id;
        $create = Permission::findByName('users.create')->id;
        $inherited = Permission::findByName('roles.view')->id;
        $page = Livewire::test(CreateUser::class)
            ->assertFormFieldExists('permission_ids', fn ($field): bool => $field instanceof ViewField)
            ->fillForm([
                'name' => 'Matrix user', 'email' => 'matrix@example.test',
                'password' => 'a-secure-test-password', 'password_confirmation' => 'a-secure-test-password',
                'location_ids' => [$location->id, $other->id], 'access_location_id' => $location->id,
                'role_ids' => [$role->id], 'permission_ids' => [$view, $create],
            ])->call('create')->assertHasNoFormErrors();
        $user = User::where('email', 'matrix@example.test')->firstOrFail();
        $manager = app(UserAccessManager::class);
        $this->assertEqualsCanonicalizing([$view, $create], $manager->assignments($user, $location->id)['permission_ids']);
        $this->assertNotContains($inherited, $manager->assignments($user, $location->id)['permission_ids']);
        $manager->sync($user, $other->id, [], [$inherited]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormSet(['permission_ids' => [$view, $create], 'role_ids' => [$role->id]])
            ->fillForm(['permission_ids' => [$view]])->call('save')->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing([$view], $manager->assignments($user, $location->id)['permission_ids']);
        $this->assertEqualsCanonicalizing([$role->id], $manager->assignments($user, $location->id)['role_ids']);
        $this->assertTrue($user->fresh()->hasPermissionTo('roles.view'));
        $this->assertEqualsCanonicalizing([$inherited], $manager->assignments($user, $other->id)['permission_ids']);
    }

    public function test_permission_matrix_uses_registered_module_relationships_and_includes_empty_modules(): void
    {
        $module = Module::factory()->create(['name' => 'Matrix module']);
        $empty = Module::factory()->create(['name' => 'Empty matrix module']);
        $permission = Permission::findByName('users.view');
        $module->permissions()->attach($permission);
        $groups = app(PermissionGroups::class)->matrix($this->admin);
        $this->assertSame($permission->id, $groups[$module->name][0]['id']);
        $this->assertSame($permission->name, $groups[$module->name][0]['name']);
        $this->assertSame([], $groups[$empty->name]);
    }

    public function test_permission_matrix_rejects_a_forged_permission_not_delegated_to_the_actor(): void
    {
        $location = Location::create(['name' => 'Scoped matrix branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $role = Role::create(['name' => 'Matrix delegate', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->syncPermissions(['users.view', 'users.create', 'permissions.assign']);
        $actor->assignRole($role);
        $this->actingAs($actor);
        $forbidden = Permission::findByName('roles.delete')->id;
        $shown = collect(app(PermissionGroups::class)->matrix($actor))->flatten(1)->pluck('id');
        $this->assertFalse($shown->contains($forbidden));
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Forged matrix user', 'email' => 'forged-matrix@example.test',
            'password' => 'a-secure-test-password', 'password_confirmation' => 'a-secure-test-password',
            'location_ids' => [$location->id], 'access_location_id' => $location->id,
            'permission_ids' => [$forbidden],
        ])->call('create')->assertHasFormErrors(['permission_ids']);
        $this->assertDatabaseMissing('users', ['email' => 'forged-matrix@example.test']);
    }
}
