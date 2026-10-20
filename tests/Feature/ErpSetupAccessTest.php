<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleDiscovery;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class ErpSetupAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_registers_real_modules_permissions_and_global_role_idempotently(): void
    {
        $user = User::factory()->create();
        $attributes = $user->fresh()->getAttributes();
        setPermissionsTeamId(123);
        $this->artisan('erp:setup-access')->expectsOutputToContain('Unmapped web permissions: (none)')->assertSuccessful();
        $this->assertSame(123, getPermissionsTeamId());
        $moduleIds = Module::pluck('id', 'code')->all();
        $permissionIds = Permission::pluck('id', 'name')->all();
        $mappings = DB::table('modules_permissions')->orderBy('id')->get()->toArray();
        $this->artisan('erp:setup-access')->assertSuccessful();

        $this->assertEqualsCanonicalizing(array_keys(app(ModuleDiscovery::class)->modules()), array_keys($moduleIds));
        $this->assertSame($moduleIds, Module::pluck('id', 'code')->all());
        $this->assertSame($permissionIds, Permission::pluck('id', 'name')->all());
        $this->assertEquals($mappings, DB::table('modules_permissions')->orderBy('id')->get()->toArray());
        $this->assertCount(count(app(ModuleRegistry::class)->implementedPermissions()), $permissionIds);
        $this->assertSame($attributes, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('model_has_roles', 0);
        $super = Role::whereNull('location_id')->where('name', 'super_admin')->where('guard_name', 'web')->sole();
        $this->assertEqualsCanonicalizing(array_values($permissionIds), $super->permissions()->pluck('permissions.id')->all());
        $this->assertSame(['access_management'], Module::where('is_active', true)->pluck('code')->all());
    }

    public function test_setup_preserves_custom_metadata_historical_mappings_and_scoped_grants(): void
    {
        $this->artisan('erp:setup-access')->assertSuccessful();
        $module = Module::where('code', 'products')->sole();
        $module->update(['description' => 'Custom description', 'name' => 'Custom catalog', 'is_active' => true]);
        $before = $module->fresh()->getAttributes();
        $legacy = Permission::create(['name' => 'legacy.export', 'guard_name' => 'web']);
        $unmapped = Permission::create(['name' => 'legacy.unmapped', 'guard_name' => 'web']);
        $api = Permission::create(['name' => 'api.export', 'guard_name' => 'api']);
        $module->permissions()->attach($legacy);
        $user = User::factory()->create();
        $location = Location::create(['name' => 'Test branch', 'type' => 'branch', 'is_active' => true]);
        setPermissionsTeamId($location->id);
        $role = Role::create(['name' => 'scoped-manager', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo($legacy);
        $user->assignRole($role);
        $user->givePermissionTo('products.view');
        $roles = DB::table('model_has_roles')->get()->toArray();
        $grants = DB::table('model_has_permissions')->get()->toArray();
        $this->artisan('erp:setup-access')->expectsOutputToContain('Unmapped web permissions: legacy.unmapped')->assertSuccessful();
        $this->assertSame($location->id, getPermissionsTeamId());
        $this->assertSame($before, $module->fresh()->getAttributes());
        $this->assertTrue($module->permissions()->whereKey($legacy)->exists());
        $this->assertEquals($roles, DB::table('model_has_roles')->get()->toArray());
        $this->assertEquals($grants, DB::table('model_has_permissions')->get()->toArray());
        $this->assertTrue($role->permissions()->whereKey($legacy)->exists());
        $super = Role::whereNull('location_id')->where('name', 'super_admin')->sole();
        $this->assertTrue($super->permissions()->whereKey($unmapped)->exists());
        $this->assertFalse($super->permissions()->whereKey($api)->exists());
    }

    public function test_inspection_is_read_only_and_setup_rejects_incomplete_schema(): void
    {
        $this->artisan('erp:setup-access', ['--inspect' => true])->assertSuccessful();
        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('roles', 0);
        Schema::shouldReceive('hasTable')->andReturnUsing(fn (string $table): bool => $table !== 'modules_permissions');
        $this->artisan('erp:setup-access')->expectsOutputToContain('Missing required tables: modules_permissions')->assertFailed();
        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    public function test_setup_rejects_invalid_team_configuration_before_any_registration(): void
    {
        config(['permission.teams' => false]);
        $this->artisan('erp:setup-access')->expectsOutputToContain('Access schema or location Teams configuration is incomplete')->assertFailed();
        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    public function test_setup_refuses_an_unrelated_database_before_reading_its_schema(): void
    {
        $connection = DB::connection();
        $original = $connection->getDatabaseName();
        $connection->setDatabaseName('e_commerce');
        Schema::shouldReceive('hasTable')->never();
        try {
            $this->artisan('erp:setup-access')->expectsOutputToContain('Setup is restricted to fox_hema_pos')->assertFailed();
        } finally {
            $connection->setDatabaseName($original);
        }
        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('permissions', 0);
    }

    public function test_registry_does_not_register_modules_absent_from_discovery(): void
    {
        $this->mock(ModuleDiscovery::class)->shouldReceive('modules')->andReturn(['access_management' => 'AccessManagement']);
        app(ModuleRegistry::class)->register();
        $this->assertSame(['access_management'], Module::pluck('code')->all());
        $this->assertDatabaseCount('permissions', 19);
    }

    public function test_all_module_migrations_are_discovered_and_prerequisites_run_first(): void
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles($migrator->paths());
        $files = array_filter($files, fn (string $file): bool => str_contains(str_replace('\\', '/', $file), '/Modules/'));
        $discovered = [];
        foreach (app(ModuleDiscovery::class)->migrationPaths() as $path) {
            foreach (glob($path.'/*.php') as $file) {
                $discovered[] = basename($file, '.php');
            }
        }
        $this->assertEqualsCanonicalizing($discovered, array_keys($files));
        $this->assertEqualsCanonicalizing($discovered, array_values(array_intersect($discovered, DB::table('migrations')->pluck('migration')->all())));
        $positions = array_flip(array_keys($files));
        foreach ([
            ['2026_10_08_000002_create_locations_table', '2026_10_04_000002_create_purchase_orders_table'],
            ['2026_10_09_000007_create_product_variants_table', '2026_10_04_000003_create_purchase_order_items_table'],
            ['2026_10_09_000023_create_stock_movements_table', '2026_10_06_000006_create_purchase_receipt_items_table'],
            ['2026_10_11_000014_create_pos_transactions_table', '2026_10_11_000002_create_payments_table'],
            ['2026_10_10_000001_create_taxes_table', '2026_10_10_000002_create_product_taxes_table'],
            ['2026_10_10_000003_create_discounts_table', '2026_10_10_000004_create_discount_products_table'],
        ] as [$parent, $child]) {
            $this->assertArrayHasKey($parent, $positions);
            $this->assertLessThan($positions[$child], $positions[$parent]);
        }
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        } else {
            $this->assertSame(1, (int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
        }
    }

    public function test_a_second_migration_run_preserves_existing_records_and_has_no_pending_files(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $migrations = DB::table('migrations')->orderBy('id')->get()->toArray();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertEquals($migrations, DB::table('migrations')->orderBy('id')->get()->toArray());
    }
}
