<?php

namespace Tests\Feature;

use App\Filament\RequestDisplayData;
use App\Http\Middleware\BeginAuthorizationRequest;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Products\Filament\Resources\Brands\Pages\ListBrands;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SharedAuthorizationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Authorized branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo('products.brands.view');
        $this->actingAs($this->actor);
    }

    private function endpoint(Closure $action): void
    {
        Route::get('/shared-auth-probe', $action)->middleware(BeginAuthorizationRequest::class);
    }

    private function denied(Closure $action): bool
    {
        try {
            $action();

            return false;
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return true;
        }
    }

    public function test_repeated_facts_reuse_database_reads_while_gate_and_policy_still_run(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $policyCalls = 0;
        Gate::after(static function () use (&$policyCalls): void {
            $policyCalls++;
        });
        $this->endpoint(function (): array {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                $this->assertFalse($this->actor->isGlobalSuperAdmin());
                $this->assertFalse(app(ModuleAccess::class)->isActive('not-installed'));
            }

            return ['allowed' => true];
        });
        $this->get('/shared-auth-probe')->assertOk()->assertJson(['allowed' => true]);
        $this->assertGreaterThanOrEqual(5, $policyCalls);
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'information_schema.tables') && str_contains($sql, 'modules_permissions')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'left join `modules_permissions`')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, '`model_has_roles` as `assignments`')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'select exists(select * from `users`')));
    }

    public function test_database_cache_writes_preserve_request_reads_but_permission_changes_still_reauthorize(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->endpoint(function (): array {
            $displayReads = 0;
            $read = function () use (&$displayReads): int {
                return ++$displayReads;
            };
            $cache = Cache::store('database');
            $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
            $this->assertSame(1, RequestDisplayData::remember('cache-write-probe', $read));

            $cache->put('authorization-performance-probe', 1, 60);
            $cache->forget('authorization-performance-probe');
            $cache->add('authorization-performance-probe', 2, 60);
            $cache->lock('authorization-performance-lock', 10)->get(fn (): bool => true);

            $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
            $this->assertSame(1, RequestDisplayData::remember('cache-write-probe', $read));
            $this->actor->revokePermissionTo('products.brands.view');
            $this->assertFalse(Gate::forUser($this->actor)->allows('viewAny', Brand::class));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'left join `modules_permissions`')));
    }

    public function test_unrelated_database_writes_invalidate_request_authorization_facts(): void
    {
        $brand = Brand::factory()->create();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->endpoint(function () use ($brand): array {
            $this->assertTrue(RequestAuthorizationFacts::actorHasLocation($this->actor, $this->branch->id));
            $this->assertTrue(RequestAuthorizationFacts::actorHasLocation($this->actor, $this->branch->id));
            DB::table('brands')->where('id', $brand->id)->update(['name' => 'Updated brand']);
            $this->assertTrue(RequestAuthorizationFacts::actorHasLocation($this->actor, $this->branch->id));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
        $this->assertCount(2, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'location_user')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_starts_with($sql, 'update `brands`')));
    }

    public function test_read_only_nested_transactions_bypass_fact_reuse_without_invalidating_outer_facts(): void
    {
        $this->endpoint(function (): array {
            $resolutions = 0;
            $readFact = function () use (&$resolutions): bool {
                return RequestAuthorizationFacts::remember('nested-read-transaction-probe', function () use (&$resolutions): bool {
                    $resolutions++;

                    return true;
                });
            };
            $this->assertTrue($readFact());
            RequestAuthorizationFacts::duringReadTransaction(function () use ($readFact): void {
                DB::transaction(function () use ($readFact): void {
                    DB::transaction(function () use ($readFact): void {
                        $this->assertTrue($readFact());
                        $this->assertTrue($readFact());
                    });
                });
            });
            $this->assertTrue($readFact());
            $this->assertSame(3, $resolutions);

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_location_deactivation_invalidates_cached_authorization_facts(): void
    {
        $this->endpoint(function (): array {
            $access = app(ProductsAccess::class);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            DB::table('locations')->where('id', $this->branch->id)->update(['is_active' => false]);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_financial_authorization_is_refreshed_after_permission_revocation(): void
    {
        $this->actor->givePermissionTo([TreasuryPermissions::VIEW, TreasuryPermissions::POST]);
        $this->endpoint(function (): array {
            $access = app(TreasuryAccess::class);
            $access->operation($this->actor, TreasuryPermissions::POST, $this->branch->id);
            $this->actor->revokePermissionTo(TreasuryPermissions::POST);
            $this->assertTrue($this->denied(
                fn () => $access->operation($this->actor, TreasuryPermissions::POST, $this->branch->id),
            ));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_sales_access_reuses_location_membership_and_active_branch_facts(): void
    {
        Module::where('code', 'sales')->update(['is_active' => true]);
        $this->actor->givePermissionTo([SalesPermissions::VIEW, SalesPermissions::UPDATE]);
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->endpoint(function (): array {
            app(LocationContext::class)->setFor($this->actor, $this->branch->id);
            $access = app(SalesAccess::class);
            $this->assertTrue($access->allows($this->actor, SalesPermissions::VIEW, $this->branch->id));
            $this->assertTrue($access->allows($this->actor, SalesPermissions::UPDATE, $this->branch->id));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'location_user')));
        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, '`type`') && str_contains($sql, '`is_active`')));
    }

    public function test_transactional_module_authorization_loads_one_mapping_snapshot_per_check(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $this->endpoint(function () use ($admin): array {
            DB::transaction(function () use ($admin): void {
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    app(ModuleAccess::class)->authorize($admin, 'products', 'products.brands.view');
                    $queries = DB::getQueryLog();
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
                $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'left join `modules_permissions`')));
                $this->assertCount(1, array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'information_schema.tables')));
                DB::table('modules')->where('code', 'products')->update(['is_active' => false]);
                $this->assertTrue($this->denied(fn () => app(ModuleAccess::class)->authorize($admin, 'products', 'products.brands.view')));
            });

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_schema_metadata_survives_data_writes_and_commits_but_not_schema_changes_or_rollbacks(): void
    {
        $this->endpoint(function (): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                DB::transaction(function (): void {
                    $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                    DB::table('users')->where('id', $this->actor->id)->update(['name' => 'Updated actor']);
                    $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                });
                $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'information_schema.tables')));
                DB::beginTransaction();
                DB::rollBack();
                $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                $this->assertCount(2, array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'information_schema.tables')));
                event(new QueryExecuted('alter table schema_probe add column flag integer', [], 0, DB::connection()));
                $this->assertTrue(RequestAuthorizationFacts::tableExists('modules_permissions'));
                $this->assertCount(3, array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'information_schema.tables')));
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_module_activation_remains_available_without_permission_mapping_table(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('modules_permissions')->andReturnFalse();

        $this->assertTrue(app(ModuleAccess::class)->isActive('products'));
    }

    public function test_module_fact_keys_match_case_insensitive_sql_identifiers(): void
    {
        $this->endpoint(function (): array {
            $access = app(ModuleAccess::class);
            $this->assertFalse($access->deniesPermission('PRODUCTS.BRANDS.VIEW'));
            $this->assertTrue($access->isActive('PRODUCTS'));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_module_activation_and_permission_mapping_changes_invalidate_facts_immediately(): void
    {
        $this->endpoint(function (): array {
            $access = app(ProductsAccess::class);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            DB::table('modules')->where('code', 'products')->update(['is_active' => false]);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            DB::table('modules')->where('code', 'products')->update(['is_active' => true]);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            $module = Module::where('code', 'products')->firstOrFail();
            $permission = Permission::where('name', 'products.brands.view')->firstOrFail();
            $module->permissions()->detach($permission);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            $module->permissions()->attach($permission);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
    }

    public function test_role_and_direct_permission_grants_and_revocations_take_effect_inside_one_request(): void
    {
        $role = Role::create(['name' => 'Branch reader', 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $this->actor->assignRole($role);
        $this->actor->revokePermissionTo('products.brands.view');
        $this->endpoint(function () use ($role): array {
            $access = app(ProductsAccess::class);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            $role->givePermissionTo('products.brands.view');
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            $role->revokePermissionTo('products.brands.view');
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            $this->actor->givePermissionTo('products.brands.view');
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            $this->actor->revokePermissionTo('products.brands.view');
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_global_admin_assignment_is_not_shared_with_other_users_or_stale_after_revocation(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $this->endpoint(function () use ($admin): array {
            $this->assertTrue($admin->isGlobalSuperAdmin());
            $this->assertFalse($this->actor->isGlobalSuperAdmin());
            $admin->load('roles');
            DB::table('model_has_roles')->where('model_id', $admin->id)->where('model_type', $admin->getMorphClass())->whereNull('location_id')->delete();
            $this->assertFalse($admin->isGlobalSuperAdmin());
            $this->assertTrue($this->denied(fn () => app(ModuleAccess::class)->authorize($admin, 'products', 'products.brands.view')));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_local_super_admin_role_never_becomes_global_and_cross_branch_checks_stay_active(): void
    {
        $role = new Role(['name' => 'super_admin', 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->save();
        $this->actor->assignRole($role);
        $foreign = Location::create(['name' => 'Unassigned', 'type' => 'branch', 'is_active' => true]);
        $this->endpoint(function () use ($foreign): array {
            $access = app(ProductsAccess::class);
            $this->assertFalse($this->actor->isGlobalSuperAdmin());
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            setPermissionsTeamId($foreign->id);
            $this->actor->unsetRelation('roles')->unsetRelation('permissions');
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            app(LocationContext::class)->setFor($this->actor, $this->branch->id);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_all_location_view_does_not_grant_cross_location_treasury_operations(): void
    {
        $this->actor->givePermissionTo([TreasuryPermissions::VIEW, TreasuryPermissions::VIEW_ALL_LOCATIONS, TreasuryPermissions::POST]);
        $foreign = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->endpoint(function () use ($foreign): array {
            $access = app(TreasuryAccess::class);
            $access->operation($this->actor, TreasuryPermissions::VIEW, $foreign->id);
            $this->assertTrue($this->denied(fn () => $access->operation($this->actor, TreasuryPermissions::POST, $foreign->id)));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_active_actor_and_membership_changes_are_not_hidden_by_memoization(): void
    {
        $this->endpoint(function (): array {
            $access = app(ProductsAccess::class);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));
            DB::table('users')->where('id', $this->actor->id)->update(['is_active' => true]);
            $this->assertTrue($access->allows($this->actor, 'products.brands.view'));
            $this->actor->locations()->detach($this->branch);
            $this->assertFalse($access->allows($this->actor, 'products.brands.view'));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_inactive_module_denial_and_mapping_requirements_apply_to_global_administrators(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $this->endpoint(function () use ($admin): array {
            $access = app(ModuleAccess::class);
            $access->authorize($admin, 'products', 'products.brands.view');
            $permission = Permission::where('name', 'products.brands.view')->firstOrFail();
            $inactive = Module::where('code', 'taxes')->firstOrFail();
            $inactive->permissions()->attach($permission);
            $this->assertTrue($this->denied(fn () => $access->authorize($admin, 'products', 'products.brands.view')));
            $this->assertFalse(Gate::forUser($admin)->allows('products.brands.view'));
            $inactive->permissions()->detach($permission);
            $access->authorize($admin, 'products', 'products.brands.view');
            Module::where('code', 'products')->update(['is_active' => false]);
            $this->assertTrue($this->denied(fn () => $access->authorize($admin, 'products', 'products.brands.view')));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_transaction_boundaries_bypass_memoization_and_discard_rolled_back_facts(): void
    {
        $this->endpoint(function (): array {
            $access = app(ModuleAccess::class);
            $this->assertTrue($access->isActive('products'));
            DB::beginTransaction();
            try {
                $count = 0;
                $this->assertTrue(RequestAuthorizationFacts::remember('transaction-probe', function () use (&$count): bool {
                    $count++;

                    return true;
                }));
                $this->assertTrue(RequestAuthorizationFacts::remember('transaction-probe', function () use (&$count): bool {
                    $count++;

                    return true;
                }));
                $this->assertSame(2, $count);
                Module::where('code', 'products')->update(['is_active' => false]);
                $this->assertFalse($access->isActive('products'));
            } finally {
                DB::rollBack();
            }
            $this->assertTrue($access->isActive('products'));

            return ['checked' => true];
        });
        $this->get('/shared-auth-probe')->assertOk();
    }

    public function test_facts_are_discarded_between_requests_in_a_reused_application(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $this->endpoint(fn (): array => ['admin' => auth()->user()->isGlobalSuperAdmin(), 'active' => app(ModuleAccess::class)->isActive('products')]);
        $this->actingAs($admin)->get('/shared-auth-probe')->assertOk()->assertJson(['admin' => true, 'active' => true]);
        $this->actingAs($this->actor)->get('/shared-auth-probe')->assertOk()->assertJson(['admin' => false, 'active' => true]);
        Module::where('code', 'products')->update(['is_active' => false]);
        $this->get('/shared-auth-probe')->assertOk()->assertJson(['admin' => false, 'active' => false]);
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_real_panel_and_livewire_requests_reauthorize_after_permission_revocation(): void
    {
        $response = $this->withSession([LocationContext::SESSION_KEY => $this->branch->id])->get('/admin/brands')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        $snapshot = null;
        foreach ($matches[1] as $candidate) {
            $decoded = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            $name = json_decode($decoded, true)['memo']['name'] ?? '';
            if ($name === ListBrands::class) {
                $snapshot = $decoded;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        $endpoint = null;
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->getName() ?? '', 'livewire.update')) {
                $endpoint = '/'.$route->uri();
                break;
            }
        }
        $this->assertNotNull($endpoint);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]];
        $this->withHeader('X-Livewire', 'true')->postJson($endpoint, $payload)->assertOk();
        setPermissionsTeamId($this->branch->id);
        $this->actor->revokePermissionTo('products.brands.view');
        $this->assertFalse(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
        Livewire::flushState();
        $this->postJson($endpoint, $payload)->assertForbidden();
        $this->get('/admin/brands')->assertForbidden();
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_console_and_outside_request_checks_never_reuse_facts(): void
    {
        $count = 0;
        RequestAuthorizationFacts::remember('outside-probe', function () use (&$count): bool {
            $count++;

            return true;
        });
        RequestAuthorizationFacts::remember('outside-probe', function () use (&$count): bool {
            $count++;

            return true;
        });
        $this->assertSame(2, $count);
    }

    public function test_locked_transactions_reuse_reads_and_reauthorize_after_writes_and_rollback(): void
    {
        $brand = Brand::factory()->create();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->endpoint(function () use ($brand, &$queries): array {
            DB::transaction(function () use ($brand, &$queries): void {
                RequestAuthorizationFacts::duringLockedTransaction(function () use ($brand, &$queries): void {
                    $start = count($queries);
                    for ($index = 0; $index < 5; $index++) {
                        ModuleRegistryLock::acquire();
                        $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                    }
                    $this->assertCount(0, array_filter(array_slice($queries, $start), fn (string $sql): bool => str_contains($sql, 'from `modules` where `code` = ? limit 1 for update')));
                    $this->assertCount(1, array_filter(array_slice($queries, $start), fn (string $sql): bool => str_contains($sql, 'left join `modules_permissions`')));

                    $brand->update(['name' => 'Changed inside checkout scope']);
                    $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                    $this->assertCount(2, array_filter(array_slice($queries, $start), fn (string $sql): bool => str_contains($sql, 'left join `modules_permissions`')));

                    DB::beginTransaction();
                    try {
                        Module::where('code', 'products')->update(['is_active' => false]);
                        $this->assertFalse(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                    } finally {
                        DB::rollBack();
                    }
                    $this->assertTrue(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                    $this->actor->revokePermissionTo('products.brands.view');
                    $this->assertFalse(Gate::forUser($this->actor)->allows('viewAny', Brand::class));
                    $this->assertTrue(RequestAuthorizationFacts::actorHasLocation($this->actor, $this->branch->id));
                    $this->actor->locations()->detach($this->branch);
                    $this->assertFalse(RequestAuthorizationFacts::actorHasLocation($this->actor, $this->branch->id));
                    $this->assertTrue(RequestAuthorizationFacts::actorIsActive($this->actor));
                    DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
                    $this->assertFalse(RequestAuthorizationFacts::actorIsActive($this->actor));
                });
            });
            $this->assertFalse(RequestAuthorizationFacts::actorIsActive($this->actor));

            return ['checked' => true];
        });

        $this->get('/shared-auth-probe')->assertOk()->assertJson(['checked' => true]);
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }

    public function test_locked_transaction_scope_cleans_up_after_an_exception(): void
    {
        $this->endpoint(function (): never {
            DB::transaction(fn () => RequestAuthorizationFacts::duringLockedTransaction(function (): never {
                $this->assertTrue(RequestAuthorizationFacts::actorIsActive($this->actor));
                DB::table('users')->where('id', $this->actor->id)->update(['is_active' => false]);
                abort(403);
            }));
        });
        $this->get('/shared-auth-probe')->assertForbidden();
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
        $this->assertDatabaseHas('users', ['id' => $this->actor->id, 'is_active' => true]);
    }

    public function test_exception_cleanup_and_database_comparison_semantics_are_preserved(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        $this->endpoint(function () use ($admin): never {
            $this->assertTrue(app(ModuleAccess::class)->isActive('PRODUCTS'));
            app(ModuleAccess::class)->authorize($admin, 'PRODUCTS', 'PRODUCTS.BRANDS.VIEW');
            abort(403);
        });
        $this->get('/shared-auth-probe')->assertForbidden();
        $this->assertFalse(request()->attributes->has('access_management.authorization_facts'));
    }
}
