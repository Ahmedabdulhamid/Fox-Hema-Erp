<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LoadTestingAccessBootstrapper;
use App\Modules\AccessManagement\Services\LoadTestingEnvironmentGuard;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Services\PosAccess;
use App\Modules\POS\Services\PosPermissions;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class LoadTestingBootstrapAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_refuses_non_load_testing_environments(): void
    {
        $this->artisan('load-testing:bootstrap-access')
            ->expectsOutputToContain('APP_ENV must be exactly load-testing')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_command_refuses_incorrect_database_targets(): void
    {
        $this->app->detectEnvironment(fn (): string => 'load-testing');

        $this->artisan('load-testing:bootstrap-access')
            ->expectsOutputToContain('The active database must be exactly pos_load_test')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('locations', 0);
    }

    public function test_environment_guard_rejects_an_unapproved_host_and_port(): void
    {
        $this->app->detectEnvironment(fn (): string => 'load-testing');

        foreach ([
            ['host' => '127.0.0.2', 'port' => '3307', 'message' => 'The active database host must be exactly 127.0.0.1.'],
            ['host' => '127.0.0.1', 'port' => '3308', 'message' => 'The active database port must be exactly 3307.'],
        ] as $target) {
            $database = Mockery::mock(DatabaseManager::class);
            $connection = Mockery::mock(Connection::class);
            $database->shouldReceive('connection')->once()->andReturn($connection);
            $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('pos_load_test');
            $connection->shouldReceive('getConfig')->with('host')->once()->andReturn($target['host']);
            if ($target['host'] === '127.0.0.1') {
                $connection->shouldReceive('getConfig')->with('port')->once()->andReturn($target['port']);
            }

            try {
                (new LoadTestingEnvironmentGuard($this->app, $database))->assertSafe();
                $this->fail('An unapproved database target was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame($target['message'], $exception->getMessage());
            }
        }
    }

    public function test_bootstrap_is_idempotent_and_preserves_the_administrator_and_branch_scopes(): void
    {
        $adminPassword = 'load-testing-only-password-with-ample-entropy-1';
        $cashierPasswords = $this->cashierPasswords();
        $bootstrapper = app(LoadTestingAccessBootstrapper::class);
        $first = $bootstrapper->bootstrap($adminPassword, $cashierPasswords);

        $administrator = User::query()->where('email', LoadTestingAccessBootstrapper::USER_EMAIL)->sole();
        $adminPasswordHash = $administrator->password;
        $adminRoleIds = DB::table('model_has_roles')->where('model_id', $administrator->getKey())
            ->where('model_type', $administrator->getMorphClass())->orderBy('role_id')->get()->toArray();
        $adminLocationIds = $administrator->locations()->orderBy('locations.id')->pluck('locations.id')->all();
        $adminRolePermissions = Role::query()->findOrFail($first['role_id'])->permissions()->orderBy('name')->pluck('name')->all();

        $second = $bootstrapper->bootstrap($adminPassword, $cashierPasswords);

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('locations', 3);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('location_user', 4);
        $this->assertSame(3, Role::query()->where('name', LoadTestingAccessBootstrapper::CASHIER_ROLE_NAME)->count());
        $this->assertSame($adminPasswordHash, $administrator->fresh()->password);
        $this->assertTrue(Hash::check($adminPassword, $administrator->fresh()->password));
        $this->assertSame($adminRoleIds, DB::table('model_has_roles')->where('model_id', $administrator->getKey())
            ->where('model_type', $administrator->getMorphClass())->orderBy('role_id')->get()->toArray());
        $this->assertSame($adminLocationIds, $administrator->fresh()->locations()->orderBy('locations.id')->pluck('locations.id')->all());
        $this->assertSame($adminRolePermissions, Role::query()->findOrFail($first['role_id'])->permissions()->orderBy('name')->pluck('name')->all());
        $this->assertFalse($administrator->isGlobalSuperAdmin());

        foreach (LoadTestingAccessBootstrapper::BRANCHES as $index => $branch) {
            $location = Location::query()->where('slug', $branch['slug'])->sole();
            $role = Role::query()->findOrFail($first['cashier_role_ids'][$index]);
            $cashier = User::query()->findOrFail($first['cashier_ids'][$index]);

            $this->assertSame($branch['name'], $location->name);
            $this->assertTrue($location->is_active);
            $this->assertSame('branch', $location->type);
            $this->assertSame($location->getKey(), $role->location_id);
            $this->assertEqualsCanonicalizing(LoadTestingAccessBootstrapper::CASHIER_PERMISSIONS, $role->permissions()->pluck('name')->all());
            $this->assertFalse($cashier->isGlobalSuperAdmin());
            $this->assertTrue(Hash::check($cashierPasswords[$branch['cashier_email']], $cashier->password));
            $this->assertSame([(int) $location->getKey()], $cashier->locations()->orderBy('locations.id')->pluck('locations.id')
                ->map(fn ($id): int => (int) $id)->all());
            $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $cashier->getKey())
                ->where('model_type', $cashier->getMorphClass())->where('role_id', $role->getKey())
                ->where('location_id', $location->getKey())->count());
        }

        foreach (LoadTestingAccessBootstrapper::REQUIRED_MODULES as $module) {
            $this->assertTrue(Module::query()->where('code', $module)->where('is_active', true)->exists());
        }
    }

    public function test_cashiers_can_use_the_checkout_chain_only_in_their_assigned_branch(): void
    {
        $result = app(LoadTestingAccessBootstrapper::class)->bootstrap(
            'load-testing-only-password-with-ample-entropy-2',
            $this->cashierPasswords(),
        );
        $locations = Location::query()->orderBy('id')->get()->keyBy('id');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (LoadTestingAccessBootstrapper::BRANCHES as $index => $branch) {
            $location = Location::query()->where('slug', $branch['slug'])->sole();
            $cashier = User::query()->findOrFail($result['cashier_ids'][$index]);
            app(LocationContext::class)->setFor($cashier, (int) $location->getKey());

            $this->assertTrue($cashier->canAccessPanel(Filament::getPanel('admin')));
            app(PosAccess::class)->authorize($cashier, PosPermissions::CHECKOUT, (int) $location->getKey());
            app(SalesAccess::class)->authorize($cashier, SalesPermissions::INVOICES_POST, (int) $location->getKey());
            app(InventoryAccess::class)->authorizePosting($cashier, (int) $location->getKey());
            app(CustomerAccess::class)->operation($cashier, PaymentsPermissions::CREATE, (int) $location->getKey());
            app(CustomerAccess::class)->operation($cashier, PaymentsPermissions::ALLOCATE, (int) $location->getKey());
            app(TreasuryAccess::class)->operation($cashier, TreasuryPermissions::POST, (int) $location->getKey());

            $foreignLocation = $locations->first(fn (Location $candidate): bool => (int) $candidate->getKey() !== (int) $location->getKey());
            app(PermissionRegistrar::class)->setPermissionsTeamId((int) $foreignLocation->getKey());
            $cashier->unsetRelation('roles')->unsetRelation('permissions');
            $this->assertFalse($cashier->can(PosPermissions::CHECKOUT));
            try {
                app(PosAccess::class)->authorize($cashier, PosPermissions::CHECKOUT, (int) $foreignLocation->getKey());
                $this->fail('A cashier was authorized outside their assigned branch.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_bootstrap_fails_closed_on_unexpected_locations_and_conflicting_cashier_credentials(): void
    {
        $bootstrapper = app(LoadTestingAccessBootstrapper::class);
        $adminPassword = 'load-testing-only-password-with-ample-entropy-3';
        $cashierPasswords = $this->cashierPasswords();
        $result = $bootstrapper->bootstrap($adminPassword, $cashierPasswords);
        $administrator = User::query()->where('email', LoadTestingAccessBootstrapper::USER_EMAIL)->sole();
        $cashier = User::query()->findOrFail($result['cashier_ids'][0]);
        $adminHash = $administrator->password;
        $existingHash = $cashier->password;

        try {
            $bootstrapper->bootstrap('different-administrator-password-with-ample-entropy', $cashierPasswords);
            $this->fail('Conflicting administrator credentials were accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('conflicts with its expected identity or credential', $exception->getMessage());
        }
        $this->assertSame($adminHash, $administrator->fresh()->password);

        $conflictingPasswords = $cashierPasswords;
        $conflictingPasswords[LoadTestingAccessBootstrapper::cashierEmails()[0]] = 'different-cashier-password-with-ample-entropy';
        try {
            $bootstrapper->bootstrap($adminPassword, $conflictingPasswords);
            $this->fail('Conflicting cashier credentials were accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('conflicts with its expected identity or credential', $exception->getMessage());
        }
        $this->assertSame($existingHash, $cashier->fresh()->password);

        Location::query()->create(['name' => 'Unexpected Test Location', 'type' => 'branch', 'is_active' => true]);
        try {
            $bootstrapper->bootstrap($adminPassword, $cashierPasswords);
            $this->fail('An unexpected location was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('Unexpected locations exist in the isolated load-testing database.', $exception->getMessage());
        }
    }

    /** @return array<string, string> */
    private function cashierPasswords(): array
    {
        return [
            LoadTestingAccessBootstrapper::cashierEmails()[0] => 'cashier-one-password-with-ample-entropy-1',
            LoadTestingAccessBootstrapper::cashierEmails()[1] => 'cashier-two-password-with-ample-entropy-2',
            LoadTestingAccessBootstrapper::cashierEmails()[2] => 'cashier-three-password-with-ample-entropy-3',
        ];
    }
}
