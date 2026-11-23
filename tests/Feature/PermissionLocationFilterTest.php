<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\AccessManagement\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\AccessManagement\Filament\Resources\SecurityEvents\Pages\ListAuthEvents;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\EditUser;
use App\Modules\AccessManagement\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\AccessManagement\Services\RoleAccessManager;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PermissionLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $own;

    private Location $foreign;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->own = Location::create(['name' => 'Own branch', 'type' => 'branch', 'is_active' => true]);
        $this->foreign = Location::create(['name' => 'Private foreign branch', 'type' => 'branch', 'is_active' => true]);
        $this->warehouse = Location::create(['name' => 'Private main warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->own);
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, $this->own->id);
        foreach (['roles.view', 'roles.create', 'roles.update', 'users.view', 'users.create', 'users.update', 'auth_events.view'] as $permission) {
            $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }

    public static function locationFilters(): array
    {
        return [
            'roles' => [ListRoles::class, 'location_id', 'roles.view'],
            'users' => [ListUsers::class, 'locations', 'users.view'],
            'security events' => [ListAuthEvents::class, 'location_id', 'auth_events.view'],
        ];
    }

    #[DataProvider('locationFilters')]
    public function test_location_filter_options_exclude_foreign_branches_and_main_warehouse(string $page, string $name, string $permission): void
    {
        $component = Livewire::test($page);
        $filter = $component->instance()->getTable()->getFilter($name);
        $this->assertSame([$this->own->id], $filter->getRelationshipQuery()->pluck('locations.id')->all());
        $field = $component->instance()->getTableFiltersForm()->getFlatFields()[$name.'.value'];
        $this->assertSame([$this->own->id], array_keys($field->getOptions()));
        $this->assertSame([], $field->getSearchResults('Private'));
    }

    #[DataProvider('locationFilters')]
    public function test_forged_filter_values_do_not_return_records_or_reveal_selected_labels(string $page, string $name, string $permission): void
    {
        [$own, $foreign] = $this->records($page);
        $component = Livewire::test($page)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$foreign]);
        foreach ([$this->foreign->id, $this->warehouse->id] as $locationId) {
            $component->filterTable($name, (string) $locationId)->assertCountTableRecords(0);
            $field = $component->instance()->getTableFiltersForm()->getFlatFields()[$name.'.value'];
            $this->assertNull($field->getSelectedRecord());
            $this->assertSame((string) $locationId, $field->getOptionLabel());
        }
        $component->filterTable($name, (string) $this->own->id)->assertCanSeeTableRecords([$own]);
    }

    #[DataProvider('locationFilters')]
    public function test_permission_and_membership_revocation_remove_options_and_block_filter_queries(string $page, string $name, string $permission): void
    {
        [$own] = $this->records($page);
        $filter = Livewire::test($page)->instance()->getTable()->getFilter($name);
        $this->actor->revokePermissionTo($permission);
        $this->assertFalse($filter->getRelationshipQuery()->exists());
        $this->assertFalse($filter->apply($own::query()->whereKey($own->id), ['value' => (string) $this->own->id])->exists());

        $this->actor->givePermissionTo($permission);
        $this->actor->locations()->detach($this->own);
        $this->assertFalse($filter->getRelationshipQuery()->exists());
        $this->assertFalse($filter->apply($own::query()->whereKey($own->id), ['value' => (string) $this->own->id])->exists());
    }

    #[DataProvider('locationFilters')]
    public function test_multi_location_users_see_the_location_authorized_in_the_current_context(string $page, string $name, string $permission): void
    {
        $this->actor->locations()->attach($this->foreign);
        $filter = Livewire::test($page)->instance()->getTable()->getFilter($name);
        $this->assertSame([$this->own->id], $filter->getRelationshipQuery()->pluck('locations.id')->all());
        app(LocationContext::class)->setFor($this->actor, $this->foreign->id);
        $this->assertFalse($filter->getRelationshipQuery()->exists());
        $this->actor->givePermissionTo($permission);
        $this->assertSame([$this->foreign->id], $filter->getRelationshipQuery()->pluck('locations.id')->all());
        app(LocationContext::class)->setFor($this->actor, $this->own->id);
        $this->assertSame([$this->own->id], $filter->getRelationshipQuery()->pluck('locations.id')->all());
    }

    #[DataProvider('locationFilters')]
    public function test_superadmins_retain_all_location_options_and_cross_location_filtering(string $page, string $name, string $permission): void
    {
        [$own, $foreign] = $this->records($page);
        app(LocationContext::class)->clear();
        $this->actor->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'location_id' => null]));
        app(LocationContext::class)->setFor($this->actor, null);
        $component = Livewire::test($page);
        $this->assertEqualsCanonicalizing([$this->own->id, $this->foreign->id, $this->warehouse->id], $component->instance()->getTable()->getFilter($name)->getRelationshipQuery()->pluck('locations.id')->all());
        $component->filterTable($name, (string) $this->foreign->id)->assertCanSeeTableRecords([$foreign]);
    }

    public function test_role_form_only_offers_the_current_authorized_scope_and_rejects_forged_submissions(): void
    {
        $this->actor->locations()->attach($this->foreign);
        $component = Livewire::test(CreateRole::class);
        $this->assertSame([$this->own->id], array_keys($component->instance()->form->getFlatFields()['location_id']->getOptions()));
        $component->fillForm(['name' => 'Forged scope', 'scope' => 'location', 'location_id' => $this->foreign->id, 'permission_ids' => []])
            ->call('create')->assertHasFormErrors(['location_id']);
        $this->assertDatabaseMissing('roles', ['name' => 'Forged scope']);
        $this->expectException(HttpException::class);
        app(RoleAccessManager::class)->save($this->actor, ['name' => 'Forged direct scope', 'location_id' => $this->foreign->id]);
    }

    public function test_location_options_query_count_does_not_grow_with_other_locations(): void
    {
        $countQueries = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            RequestAuthorizationFacts::duringRead(fn (): array => app(LocationContext::class)
                ->scopeLocationQuery(Location::query(), $this->actor, 'roles.view')->pluck('name', 'id')->all());
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $before = $countQueries();
        for ($index = 0; $index < 15; $index++) {
            Location::create(['name' => 'Unrelated '.$index, 'type' => 'branch', 'is_active' => true]);
        }
        $this->assertLessThanOrEqual($before, $countQueries());
    }

    public function test_shared_user_location_labels_and_permission_form_state_do_not_expose_unassigned_locations(): void
    {
        [$target] = $this->records(ListUsers::class);
        Livewire::test(ListUsers::class)->assertDontSee($this->foreign->name)->assertDontSee($this->warehouse->name);
        $form = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);
        $this->assertSame([$this->own->id], array_map('intval', $form->get('data.location_ids')));
    }

    public function test_shared_user_locations_already_assigned_to_the_manager_remain_visible(): void
    {
        $this->actor->locations()->attach($this->foreign);
        [$target] = $this->records(ListUsers::class);
        Livewire::test(ListUsers::class)->assertSee($this->foreign->name)->assertDontSee($this->warehouse->name);
        $form = Livewire::test(EditUser::class, ['record' => $target->getRouteKey()]);
        $this->assertEqualsCanonicalizing([$this->own->id, $this->foreign->id], array_map('intval', $form->get('data.location_ids')));
    }

    /** @return array{Model, Model} */
    private function records(string $page): array
    {
        if ($page === ListRoles::class) {
            return [
                Role::create(['name' => 'Local role', 'guard_name' => 'web', 'location_id' => $this->own->id]),
                Role::create(['name' => 'Foreign role', 'guard_name' => 'web', 'location_id' => $this->foreign->id]),
            ];
        }
        if ($page === ListAuthEvents::class) {
            return [
                AuthEvent::create(['event' => 'local_test', 'location_id' => $this->own->id, 'occurred_at' => now()]),
                AuthEvent::create(['event' => 'foreign_test', 'location_id' => $this->foreign->id, 'occurred_at' => now()]),
            ];
        }
        $own = User::factory()->create(['is_active' => true]);
        $own->locations()->attach([$this->own->id, $this->foreign->id, $this->warehouse->id]);
        $foreign = User::factory()->create(['is_active' => true]);
        $foreign->locations()->attach($this->foreign);

        return [$own, $foreign];
    }
}
