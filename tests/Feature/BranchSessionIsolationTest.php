<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Filament\Resources\Sessions\DatabaseSessionResource;
use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\BranchDatabaseSessionHandler;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\SessionManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class BranchSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_cannot_read_or_revoke_another_branch_session_of_a_shared_user(): void
    {
        $own = Location::create(['name' => 'Own', 'type' => 'branch', 'is_active' => true]);
        $foreign = Location::create(['name' => 'Foreign', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $target = User::factory()->create();
        $actor->locations()->attach($own);
        $target->locations()->attach([$own->id, $foreign->id]);
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $own->id);
        foreach (['sessions.view', 'sessions.revoke'] as $name) {
            $actor->givePermissionTo(Permission::create(['name' => $name, 'guard_name' => 'web']));
        }
        DB::table('sessions')->insert([
            'id' => 'foreign-session', 'user_id' => $target->id, 'location_id' => $foreign->id,
            'payload' => base64_encode(json_encode(['access_management' => ['location_id' => $foreign->id]])),
            'last_activity' => time(),
        ]);
        $session = DatabaseSession::findOrFail('foreign-session');

        $this->assertFalse($actor->can('view', $session));
        $this->assertFalse($actor->can('delete', $session));
        $this->assertFalse(DatabaseSessionResource::getEloquentQuery()->whereKey($session->id)->exists());
        try {
            app(SessionManager::class)->revoke($session->id, $target);
            $this->fail('Foreign session revocation was allowed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('sessions', ['id' => $session->id]);
        }
    }

    public function test_bulk_revocation_is_limited_to_the_current_authorized_branch(): void
    {
        [$actor, $own, $foreign] = $this->branchUser();
        $target = User::factory()->create();
        $target->locations()->attach([$own->id, $foreign->id]);
        foreach (['own' => $own->id, 'foreign' => $foreign->id, 'unknown' => null] as $id => $locationId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $target->id, 'location_id' => $locationId, 'payload' => '', 'last_activity' => time()]);
        }
        $this->assertTrue($actor->can('view', DatabaseSession::findOrFail('own')));
        $this->assertFalse($actor->can('view', DatabaseSession::findOrFail('unknown')));
        $this->assertSame(['own'], DatabaseSessionResource::getEloquentQuery()->pluck('id')->all());
        $this->assertSame(1, app(SessionManager::class)->revokeAll($target));
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
        $this->assertDatabaseHas('sessions', ['id' => 'unknown']);
    }

    public function test_forged_requests_and_cookies_cannot_switch_to_a_foreign_branch_or_warehouse(): void
    {
        [$actor, $own, $foreign] = $this->branchUser();
        $this->withSession([LocationContext::SESSION_KEY => $foreign->id])
            ->withCookie('branch_id', (string) $foreign->id)
            ->withCookie('location_id', (string) $foreign->id);
        foreach ([$foreign->id, 'global'] as $locationId) {
            $this->post(route('access-management.location.update'), ['location_id' => $locationId])->assertForbidden();
            $this->assertSame($own->id, session(LocationContext::SESSION_KEY));
        }
        $this->post(route('access-management.location.update'), ['location_id' => $own->id.'junk'])->assertSessionHasErrors('location_id');
        $this->actingAs($actor)->get('/admin/sessions/database-sessions?location_id='.$foreign->id)->assertOk();
        $this->assertSame($own->id, session(LocationContext::SESSION_KEY));
    }

    public function test_authorized_account_deactivation_can_still_invalidate_sessions_without_revoke_permission(): void
    {
        [$actor, $own, $other] = $this->branchUser();
        $actor->revokePermissionTo('sessions.revoke');
        $actor->givePermissionTo(Permission::findOrCreate('users.update', 'web'));
        $target = User::factory()->create(['is_active' => false]);
        $target->locations()->attach([$own->id, $other->id]);
        DB::table('sessions')->insert(['id' => 'disabled-session', 'user_id' => $target->id, 'location_id' => $own->id, 'payload' => '', 'last_activity' => time()]);

        $this->assertSame(1, app(SessionManager::class)->revokeAll($target));
        $this->assertDatabaseMissing('sessions', ['id' => 'disabled-session']);
    }

    public function test_authorized_multi_branch_switching_preserves_location_permissions(): void
    {
        [$actor, $own, $other] = $this->branchUser();
        $actor->locations()->attach($other);
        app(LocationContext::class)->setFor($actor, $other->id);
        $actor->givePermissionTo('sessions.view');
        app(LocationContext::class)->setFor($actor, $own->id);

        $this->post(route('access-management.location.update'), ['location_id' => $other->id])->assertRedirect();
        $this->assertSame($other->id, session(LocationContext::SESSION_KEY));
        app(LocationContext::class)->initializeFor($actor);
        $this->assertTrue($actor->can('sessions.view'));
        $this->assertFalse($actor->can('sessions.revoke'));
        $this->post(route('access-management.location.update'), ['location_id' => $own->id])->assertRedirect();
        $this->assertSame($own->id, session(LocationContext::SESSION_KEY));
    }

    public function test_branch_reassignment_and_permission_revocation_invalidate_stale_context(): void
    {
        [$actor, $own, $other] = $this->branchUser();
        $actor->locations()->attach($other);
        app(LocationContext::class)->setFor($actor, $other->id);
        $actor->givePermissionTo('sessions.view');
        app(LocationContext::class)->setFor($actor, $own->id);
        $actor->locations()->detach($own);
        $this->get('/admin/sessions/database-sessions')->assertOk();
        $this->assertSame($other->id, session(LocationContext::SESSION_KEY));

        app(LocationContext::class)->setFor($actor, $other->id);
        $actor->revokePermissionTo('sessions.view');
        $this->get('/admin/sessions/database-sessions')->assertForbidden();
        $this->assertNull(session(LocationContext::SESSION_KEY));
        $this->assertNull(getPermissionsTeamId());
    }

    public function test_removing_all_locations_clears_the_forged_or_stale_context(): void
    {
        [$actor] = $this->branchUser();
        $actor->locations()->detach();
        try {
            app(LocationContext::class)->initializeFor($actor);
            $this->fail('A location was retained without an assignment.');
        } catch (AuthorizationException) {
            $this->assertNull(session(LocationContext::SESSION_KEY));
            $this->assertNull(getPermissionsTeamId());
        }
    }

    public function test_context_and_session_ownership_do_not_leak_between_users(): void
    {
        [$first, $own, $other] = $this->branchUser();
        DB::table('sessions')->insert(['id' => 'first-session', 'user_id' => $first->id, 'location_id' => null, 'payload' => '', 'last_activity' => time()]);
        $this->assertTrue($first->can('view', DatabaseSession::findOrFail('first-session')));
        $second = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $second->locations()->attach($other);
        $this->actingAs($second);
        app(LocationContext::class)->setFor($second, $other->id);
        $second->givePermissionTo('sessions.view');
        session()->put(LocationContext::SESSION_KEY, $own->id);
        $this->get('/admin/sessions/database-sessions')->assertOk();
        $this->assertSame($other->id, session(LocationContext::SESSION_KEY));
        $this->assertFalse($second->can('view', DatabaseSession::findOrFail('first-session')));
        $this->assertFalse(DatabaseSessionResource::getEloquentQuery()->whereKey('first-session')->exists());
    }

    public static function sessionEncryptionModes(): array
    {
        return ['plain' => [false], 'encrypted' => [true]];
    }

    #[DataProvider('sessionEncryptionModes')]
    public function test_database_driver_writes_validated_metadata(bool $encrypted): void
    {
        [$actor, $own, $foreign] = $this->branchUser();
        config()->set(['session.driver' => 'database', 'session.encrypt' => $encrypted]);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $store = app('session.store');
        $this->assertInstanceOf(BranchDatabaseSessionHandler::class, $store->getHandler());
        $store->start();
        $store->put(LocationContext::SESSION_KEY, $own->id);
        $store->save();
        $this->assertDatabaseHas('sessions', ['id' => $store->getId(), 'user_id' => $actor->id, 'location_id' => $own->id]);
        $serialized = base64_decode(DB::table('sessions')->where('id', $store->getId())->value('payload'));
        if ($encrypted) {
            $serialized = app('encrypter')->decrypt($serialized);
        }
        $this->assertSame($own->id, data_get(json_decode($serialized, true), LocationContext::SESSION_KEY));

        $store->put(LocationContext::SESSION_KEY, $foreign->id);
        $store->save();
        $this->assertDatabaseHas('sessions', ['id' => $store->getId(), 'location_id' => null]);
        $store->put(LocationContext::SESSION_KEY, $own->id);
        $actor->locations()->detach($own);
        $store->save();
        $this->assertDatabaseHas('sessions', ['id' => $store->getId(), 'location_id' => null]);
    }

    public function test_superadmin_can_manage_sessions_in_any_branch_including_legacy_sessions(): void
    {
        [$target, , $foreign] = $this->branchUser();
        $admin = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actingAs($admin);
        app(LocationContext::class)->clear();
        $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'location_id' => null]));
        app(LocationContext::class)->setFor($admin, null);
        foreach (['foreign' => $foreign->id, 'legacy' => null] as $id => $locationId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $target->id, 'location_id' => $locationId, 'payload' => '', 'last_activity' => time()]);
            $this->assertTrue($admin->can('view', DatabaseSession::findOrFail($id)));
            $this->assertTrue($admin->can('delete', DatabaseSession::findOrFail($id)));
        }
        $this->assertSame(2, app(SessionManager::class)->revokeAll($target));
    }

    /** @return array{User, Location, Location} */
    private function branchUser(): array
    {
        $own = Location::create(['name' => 'Own', 'type' => 'branch', 'is_active' => true]);
        $other = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($own);
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $own->id);
        foreach (['sessions.view', 'sessions.revoke'] as $name) {
            $actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }

        return [$actor, $own, $other];
    }
}
