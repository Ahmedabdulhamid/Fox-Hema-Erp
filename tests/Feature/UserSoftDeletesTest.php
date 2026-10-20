<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ViewUser;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class UserSoftDeletesTest extends TestCase
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
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_trashed_filter_and_record_actions_delete_restore_and_permanently_delete_users(): void
    {
        $user = User::factory()->create();
        $page = Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$user])
            ->assertActionVisible(TestAction::make('delete')->table($user))
            ->assertActionHidden(TestAction::make('restore')->table($user))
            ->assertActionHidden(TestAction::make('forceDelete')->table($user))
            ->callAction(TestAction::make('delete')->table($user));
        $this->assertSoftDeleted($user);
        $page->assertCanNotSeeTableRecords([$user])->filterTable('trashed', true)->assertCanSeeTableRecords([$user, $this->admin])
            ->filterTable('trashed', false)->assertCanSeeTableRecords([$user])->assertCanNotSeeTableRecords([$this->admin])
            ->assertActionHidden(TestAction::make('delete')->table($user))
            ->assertActionVisible(TestAction::make('restore')->table($user))
            ->assertActionVisible(TestAction::make('forceDelete')->table($user))
            ->callAction(TestAction::make('restore')->table($user));
        $this->assertNotSoftDeleted($user);
        $user->refresh()->delete();
        Livewire::test(ListUsers::class)->filterTable('trashed', false)->callAction(TestAction::make('forceDelete')->table($user));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_trashed_users_can_be_opened_and_restored_from_the_view_page(): void
    {
        $user = User::factory()->create();
        $user->delete();
        $this->get('/admin/users/'.$user->getRouteKey())->assertOk();
        Livewire::test(ViewUser::class, ['record' => $user->getRouteKey()])
            ->assertActionVisible('restore')->assertActionVisible('forceDelete')->assertActionHidden('delete')->callAction('restore');
        $this->assertNotSoftDeleted($user);
    }

    public function test_trashed_users_can_be_opened_and_permanently_deleted_from_the_edit_page(): void
    {
        $user = User::factory()->create();
        $user->delete();
        $this->get('/admin/users/'.$user->getRouteKey().'/edit')->assertOk();
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->assertActionVisible('restore')->assertActionVisible('forceDelete')->assertActionHidden('delete')->callAction('forceDelete');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_bulk_actions_protect_the_current_account_and_super_admins(): void
    {
        $users = User::factory()->count(2)->create();
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole(Role::where('name', 'super_admin')->firstOrFail());
        Livewire::test(ListUsers::class)->selectTableRecords([$this->admin->id, $otherAdmin->id, ...$users->modelKeys()])
            ->callAction(TestAction::make('delete')->table()->bulk());
        $this->assertNotSoftDeleted($this->admin);
        $this->assertNotSoftDeleted($otherAdmin);
        foreach ($users as $user) {
            $this->assertSoftDeleted($user);
        }
        Livewire::test(ListUsers::class)->filterTable('trashed', false)->selectTableRecords($users->modelKeys())
            ->callAction(TestAction::make('restore')->table()->bulk());
        foreach ($users as $user) {
            $this->assertNotSoftDeleted($user);
            $user->refresh()->delete();
        }
        $otherAdmin->delete();
        Livewire::test(ListUsers::class)->filterTable('trashed', false)->selectTableRecords([$otherAdmin->id, ...$users->modelKeys()])
            ->callAction(TestAction::make('forceDelete')->table()->bulk());
        $this->assertSoftDeleted($otherAdmin);
        foreach ($users as $user) {
            $this->assertDatabaseMissing('users', ['id' => $user->id]);
        }
    }

    public function test_scoped_read_only_user_cannot_restore_or_force_delete_and_cannot_view_foreign_trashed_users(): void
    {
        $location = Location::create(['name' => 'Own', 'type' => 'branch', 'is_active' => true]);
        $foreign = Location::create(['name' => 'Foreign', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($location);
        $ownUser = User::factory()->create();
        $ownUser->locations()->attach($location);
        $foreignUser = User::factory()->create();
        $foreignUser->locations()->attach($foreign);
        $ownUser->delete();
        $foreignUser->delete();
        app(LocationContext::class)->setFor($actor, $location->id);
        $actor->givePermissionTo('users.view');
        $this->actingAs($actor);
        Livewire::test(ListUsers::class)->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$ownUser])->assertCanNotSeeTableRecords([$foreignUser])
            ->assertActionHidden(TestAction::make('restore')->table($ownUser))
            ->assertActionHidden(TestAction::make('forceDelete')->table($ownUser))
            ->assertActionHidden(TestAction::make('restore')->table()->bulk())
            ->assertActionHidden(TestAction::make('forceDelete')->table()->bulk());
        $this->get('/admin/users/'.$foreignUser->getRouteKey())->assertNotFound();
        $this->assertSoftDeleted($ownUser);
    }
}
