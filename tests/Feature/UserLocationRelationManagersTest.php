<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource;
use App\Modules\AccessManagement\Filament\Resources\Locations\Pages\EditLocation;
use App\Modules\AccessManagement\Filament\Resources\Locations\RelationManagers\UsersRelationManager;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ViewUser;
use App\Modules\AccessManagement\Filament\Resources\Users\RelationManagers\LocationsRelationManager;
use App\Modules\AccessManagement\Filament\Resources\Users\UserResource;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\UserLocationManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UserLocationRelationManagersTest extends TestCase
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
        Livewire::withoutLazyLoading();
    }

    public function test_user_relation_manager_attaches_and_detaches_locations_without_deleting_records(): void
    {
        $user = User::factory()->create();
        $location = $this->location('Main');
        $this->assertContains(LocationsRelationManager::class, UserResource::getRelations());

        $manager = Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);
        $manager->callAction(TestAction::make('attach')->table(), data: ['recordId' => $location->id])->assertHasNoFormErrors();
        $manager->assertCanSeeTableRecords([$location]);
        $this->assertDatabaseHas('location_user', ['user_id' => $user->id, 'location_id' => $location->id]);
        $this->assertNotNull($user->locations()->firstOrFail()->pivot->created_at);
        $this->assertDatabaseHas('auth_events', ['event' => 'user_location_attached', 'target_user_id' => $user->id]);

        $manager->callAction(TestAction::make('detach')->table($location))->assertHasNoFormErrors();
        $this->assertDatabaseMissing('location_user', ['user_id' => $user->id, 'location_id' => $location->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
        $this->assertDatabaseHas('auth_events', ['event' => 'user_location_detached', 'target_user_id' => $user->id]);
    }

    public function test_location_relation_manager_manages_existing_users(): void
    {
        $user = User::factory()->create();
        $location = $this->location('Warehouse', 'warehouse');
        $this->assertContains(UsersRelationManager::class, LocationResource::getRelations());

        $manager = Livewire::test(UsersRelationManager::class, ['ownerRecord' => $location, 'pageClass' => EditLocation::class]);
        $manager->callAction(TestAction::make('attach')->table(), data: ['recordId' => $user->id])->assertHasNoFormErrors();
        $manager->assertCanSeeTableRecords([$user]);
        $this->assertDatabaseHas('location_user', ['user_id' => $user->id, 'location_id' => $location->id]);

        $manager->callAction(TestAction::make('detach')->table($user));
        $this->assertDatabaseMissing('location_user', ['user_id' => $user->id, 'location_id' => $location->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
    }

    public function test_view_page_and_view_only_users_cannot_modify_memberships(): void
    {
        $location = $this->location('Main');
        $user = User::factory()->create();
        $user->locations()->attach($location);

        Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => ViewUser::class])
            ->assertCanSeeTableRecords([$location])
            ->assertActionHidden(TestAction::make('attach')->table())
            ->assertActionHidden(TestAction::make('detach')->table($location));

        $actor = $this->scopedActor($location, ['users.view', 'locations.view']);
        $this->actingAs($actor);
        Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->assertActionHidden(TestAction::make('attach')->table())
            ->assertActionHidden(TestAction::make('detach')->table($location));
        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $location, 'pageClass' => EditLocation::class])
            ->assertActionHidden(TestAction::make('attach')->table())
            ->assertActionHidden(TestAction::make('detach')->table($user));
    }

    public function test_scoped_manager_cannot_attach_a_foreign_location_using_a_forged_id(): void
    {
        $own = $this->location('Own');
        $foreign = $this->location('Foreign');
        $user = User::factory()->create();
        $user->locations()->attach([$own->id, $foreign->id]);
        $this->actingAs($this->scopedActor($own, ['users.view', 'users.update', 'locations.view']));

        $manager = Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);
        $manager->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
        $manager->assertActionVisible(TestAction::make('attach')->table())
            ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $foreign->id])->assertHasFormErrors(['recordId']);
        $this->assertDatabaseHas('location_user', ['user_id' => $user->id, 'location_id' => $foreign->id]);
    }

    public function test_scoped_manager_cannot_attach_foreign_users_or_modify_a_super_admin(): void
    {
        $own = $this->location('Own');
        $foreign = $this->location('Foreign');
        $user = User::factory()->create();
        $user->locations()->attach($foreign);
        $this->admin->locations()->attach($own);
        $this->actingAs($this->scopedActor($own, ['users.view', 'users.update', 'locations.view']));

        $manager = Livewire::test(UsersRelationManager::class, ['ownerRecord' => $own, 'pageClass' => EditLocation::class]);
        $manager->assertActionHidden(TestAction::make('detach')->table($this->admin));
        $manager->assertActionVisible(TestAction::make('attach')->table())
            ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $user->id])->assertHasFormErrors(['recordId']);
        $this->assertDatabaseMissing('location_user', ['user_id' => $user->id, 'location_id' => $own->id]);
    }

    public function test_inactive_locations_cannot_be_attached_and_duplicate_assignments_are_idempotent(): void
    {
        $user = User::factory()->create();
        $location = $this->location('Main');
        $service = app(UserLocationManager::class);
        $service->attach($user, $location);
        $service->attach($user, $location);
        $this->assertSame(1, $user->locations()->count());

        $location->update(['is_active' => false]);
        Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class])
            ->callAction(TestAction::make('attach')->table(), data: ['recordId' => $location->id])->assertHasFormErrors(['recordId']);
        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $location, 'pageClass' => EditLocation::class])
            ->assertActionHidden(TestAction::make('attach')->table());
    }

    public function test_saving_an_open_user_form_preserves_memberships_changed_by_the_relation_manager(): void
    {
        $user = User::factory()->create();
        $first = $this->location('First');
        $second = $this->location('Second');
        $user->locations()->attach($first);
        $page = Livewire::test(EditUser::class, ['record' => $user->getRouteKey()]);
        app(UserLocationManager::class)->attach($user, $second);

        $page->fillForm(['name' => 'Updated name'])->call('save')->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $user->locations()->pluck('locations.id')->all());
    }

    public function test_scoped_manager_can_manage_owned_locations_and_preserve_other_assignments(): void
    {
        $first = $this->location('First');
        $second = $this->location('Second');
        $foreign = $this->location('Foreign');
        $user = User::factory()->create();
        $user->locations()->attach([$first->id, $foreign->id]);
        $actor = $this->scopedActor($first, ['users.view', 'users.update', 'locations.view']);
        $actor->locations()->attach($second);
        $this->actingAs($actor);

        $manager = Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $user, 'pageClass' => EditUser::class]);
        $manager->callAction(TestAction::make('attach')->table(), data: ['recordId' => $second->id])->assertHasNoFormErrors();
        $this->assertDatabaseHas('location_user', ['user_id' => $user->id, 'location_id' => $second->id]);
        $manager->callAction(TestAction::make('detach')->table($second));
        $this->assertEqualsCanonicalizing([$first->id, $foreign->id], $user->locations()->pluck('locations.id')->all());
    }

    private function location(string $name, string $type = 'branch'): Location
    {
        return Location::create(['name' => $name, 'type' => $type, 'is_active' => true]);
    }

    private function scopedActor(Location $location, array $permissions): User
    {
        $actor = User::factory()->create(['must_change_password' => false]);
        $actor->locations()->attach($location);
        app(LocationContext::class)->setFor($actor, $location->id);
        $role = Role::create(['name' => 'manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->syncPermissions($permissions);
        $actor->assignRole($role);

        return $actor;
    }
}
