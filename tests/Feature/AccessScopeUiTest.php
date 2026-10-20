<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Pages\Auth\Login;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\CreateUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AccessScopeUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('access-management.seed_admin', ['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'a-secure-test-password']);
        $this->seed(AccessManagementSeeder::class);
        $this->provisionTestSuperAdmin();
        $this->admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->admin->update(['must_change_password' => false]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function location(string $name): Location
    {
        return Location::create(['name' => $name, 'type' => 'warehouse', 'is_active' => true]);
    }

    public function test_global_assignments_can_be_reviewed_and_saved_in_the_users_location_without_changing_other_scopes(): void
    {
        $location = $this->location('Main');
        $other = $this->location('Other');
        $user = User::factory()->create(['must_change_password' => false]);
        $user->locations()->attach([$location->id, $other->id]);
        $role = Role::create(['name' => 'Cashier', 'guard_name' => 'web', 'location_id' => null]);
        $user->assignRole($role);
        $user->givePermissionTo('users.view');
        $this->actingAs($this->admin);
        app(UserAccessManager::class)->sync($user, $other->id, [], [Permission::findByName('roles.view')->id]);
        $page = Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertFormSet(['access_location_id' => $location->id, 'role_ids' => [], 'permission_ids' => []])
            ->callAction(TestAction::make('loadGlobalAssignments')->schemaComponent('accessAssignments'))->assertHasNoFormErrors()
            ->assertFormSet(['role_ids' => [$role->id], 'permission_ids' => [Permission::findByName('users.view')->id]])
            ->assertSee(__('Selected permissions allow panel access in this location when the account is active.'));
        $this->assertSame([], app(UserAccessManager::class)->assignments($user, $location->id)['role_ids']);
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame([$role->id], app(UserAccessManager::class)->assignments($user, $location->id)['role_ids']);
        $this->assertSame([$role->id], app(UserAccessManager::class)->assignments($user, null)['role_ids']);
        $this->assertSame([Permission::findByName('roles.view')->id], app(UserAccessManager::class)->assignments($user, $other->id)['permission_ids']);
        $this->assertNull(getPermissionsTeamId());
        $this->assertTrue($user->fresh()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame($location->id, session(LocationContext::SESSION_KEY));
    }

    public function test_create_user_and_inline_role_use_the_selected_location_and_role_permissions(): void
    {
        $location = $this->location('Main');
        $this->actingAs($this->admin);
        $permission = Permission::findByName('users.view');
        $page = Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Scoped user', 'email' => 'scoped@example.test', 'password' => 'a-secure-test-password', 'password_confirmation' => 'a-secure-test-password',
            'location_ids' => [$location->id], 'access_location_id' => $location->id,
        ])->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'Cashier', 'permission_ids' => [$permission->id]])->assertHasNoFormErrors();
        $role = Role::where('name', 'Cashier')->firstOrFail();
        $this->assertSame($location->id, $role->location_id);
        $this->assertTrue($role->hasPermissionTo($permission));
        $page->callAction(TestAction::make('createOption')->schemaComponent('role_ids'), data: ['name' => 'Cashier'])
            ->assertHasFormErrors(['name' => 'unique'])->unmountAction();
        $page->call('create')->assertHasNoFormErrors();
        $user = User::where('email', 'scoped@example.test')->firstOrFail();
        $this->assertSame([$role->id], app(UserAccessManager::class)->assignments($user, $location->id)['role_ids']);
        $this->assertSame([], app(UserAccessManager::class)->assignments($user, null)['role_ids']);
    }

    public function test_login_selects_an_authorized_location_and_recovers_from_a_stale_session_location(): void
    {
        $first = $this->location('No access');
        $second = $this->location('Allowed');
        $stale = $this->location('Stale');
        $user = User::factory()->create(['must_change_password' => false]);
        $user->locations()->attach([$first->id, $second->id]);
        $this->actingAs($this->admin);
        app(UserAccessManager::class)->sync($user, $second->id, [], [Permission::findByName('users.view')->id]);
        auth()->logout();
        session()->put(LocationContext::SESSION_KEY, $stale->id);
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($second->id, session(LocationContext::SESSION_KEY));
    }

    public static function loginFailures(): array
    {
        return [
            'inactive' => [false, false, 'Your account is inactive. Contact your administrator.'],
            'no location' => [true, false, 'No active location is assigned to your account. Contact your administrator.'],
            'no permission' => [true, true, 'Your account has no panel access permissions in its assigned locations. Ask your administrator to review your location roles and permissions.'],
        ];
    }

    #[DataProvider('loginFailures')]
    public function test_login_explains_access_failure_only_after_valid_credentials(bool $active, bool $hasLocation, string $message): void
    {
        $user = User::factory()->create(['is_active' => $active]);
        if ($hasLocation) {
            $user->locations()->attach($this->location('Main'));
        }
        $page = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email'])->assertSee(__($message));
        $this->assertGuest();
        $page->fillForm(['password' => 'wrong-password'])->call('authenticate')->assertHasFormErrors(['email'])->assertDontSee(__($message));
        $this->assertGuest();
        $this->assertDatabaseHas('auth_events', ['event' => 'login_failed']);
    }

    public function test_switch_location_rejects_foreign_and_unpermitted_locations_and_preserves_context(): void
    {
        $own = $this->location('Own');
        $noAccess = $this->location('No access');
        $foreign = $this->location('Foreign');
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach([$own->id, $noAccess->id]);
        $this->actingAs($this->admin);
        app(UserAccessManager::class)->sync($user, $own->id, [], [Permission::findByName('users.view')->id]);
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, $own->id);
        $this->post(route('access-management.location.update'), ['location_id' => $foreign->id])->assertForbidden();
        $this->post(route('access-management.location.update'), ['location_id' => $noAccess->id])->assertSessionHasErrors('location_id');
        $this->assertSame($own->id, session(LocationContext::SESSION_KEY));
        $this->post(route('access-management.location.update'), ['location_id' => 'global'])->assertForbidden();
        $this->post(route('access-management.location.update'), ['location_id' => $own->id])->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_scoped_manager_cannot_save_permissions_in_another_location(): void
    {
        $own = $this->location('Own');
        $foreign = $this->location('Foreign');
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $target = User::factory()->create();
        $actor->locations()->attach([$own->id, $foreign->id]);
        $target->locations()->attach([$own->id, $foreign->id]);
        $this->actingAs($this->admin);
        app(UserAccessManager::class)->sync($actor, $own->id, [], Permission::whereIn('name', ['users.view', 'users.update'])->pluck('id')->all());
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $own->id);
        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])->set('data.access_location_id', $foreign->id)->assertForbidden();
        $this->assertSame([], app(UserAccessManager::class)->assignments($target, $foreign->id)['permission_ids']);
    }

    public function test_saving_the_super_admin_form_preserves_the_system_role(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])->fillForm(['name' => 'Updated admin'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->admin->fresh()->isGlobalSuperAdmin());
    }
}
