<?php

namespace App\Console\Commands;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Services\ModuleDiscovery;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('erp:setup-access {--inspect : Report current database and access state without writing}')]
#[Description('Register discovered ERP modules and implemented permissions without migrating or provisioning users')]
class SetupErpAccess extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ModuleRegistry $registry, ModuleDiscovery $discovery): int
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $this->info('Connection: '.$connection->getName().'; database: '.$database);
        $isolatedTest = app()->environment('testing') && (
            ($connection->getDriverName() === 'sqlite' && $database === ':memory:')
            || ($connection->getDriverName() === 'mysql' && str_starts_with($database, 'codex_public_ids_test_'))
        );
        if (! $this->option('inspect') && $database !== 'fox_hema_pos' && ! $isolatedTest) {
            $this->error('Setup is restricted to fox_hema_pos or an isolated test database.');

            return self::FAILURE;
        }
        $this->line('Discovered module directories: '.count($discovery->modules()));
        $migrator = app('migrator');
        $migrationFiles = $migrator->getMigrationFiles($migrator->paths());
        $applied = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $this->line('Discovered migrations: '.count($migrationFiles).'; pending: '.count(array_diff(array_keys($migrationFiles), $applied)));
        $tables = ['users', 'modules', 'modules_permissions', ...array_values(config('permission.table_names'))];
        $missing = array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            $this->error('Missing required tables: '.implode(', ', $missing).'. Review and apply migrations separately.');

            return self::FAILURE;
        }
        if (! $this->option('inspect')) {
            if (! config('permission.teams') || config('permission.column_names.team_foreign_key') !== 'location_id'
                || ! Schema::hasColumn(config('permission.table_names.roles'), 'location_id')
                || ! Schema::hasColumn(config('permission.table_names.model_has_roles'), 'location_id')
                || ! Schema::hasColumn(config('permission.table_names.model_has_permissions'), 'location_id')
                || ! Schema::hasColumns('modules', ['uuid', 'slug'])
                || ! Schema::hasColumns(config('permission.table_names.roles'), ['uuid', 'slug'])
                || ! Schema::hasColumns(config('permission.table_names.permissions'), ['uuid', 'slug'])) {
                $this->error('Access schema or location Teams configuration is incomplete. Review migrations separately.');

                return self::FAILURE;
            }
            if (! isset($discovery->modules()['access_management'])) {
                $this->error('The protected AccessManagement module directory is missing.');

                return self::FAILURE;
            }
            $registry->register();
        }
        $modules = Module::query()->with(['permissions' => fn ($query) => $query->where('guard_name', 'web')])->orderBy('code')->get();
        $this->table(['Module', 'Directory', 'Active', 'Web permissions'], $modules->map(fn (Module $module): array => [
            $module->code, $discovery->modules()[$module->code] ?? '(historical)',
            $module->is_active ? 'yes' : 'no', $module->permissions->count(),
        ])->all());
        foreach ($modules as $module) {
            $this->line($module->code.': '.($module->permissions->pluck('name')->sort()->implode(', ') ?: '(none)'));
        }
        $this->info('Registered web permissions: '.Permission::query()->where('guard_name', 'web')->count());
        $unmapped = Permission::query()->where('guard_name', 'web')->whereNotIn('id', DB::table('modules_permissions')->select('permission_id'))->orderBy('name')->pluck('name');
        $this->line('Unmapped web permissions: '.($unmapped->implode(', ') ?: '(none)'));
        $this->line('Users: '.DB::table('users')->count().'; role assignments: '.DB::table(config('permission.table_names.model_has_roles'))->count());
        foreach (['products', 'taxes', 'discounts'] as $table) {
            if (Schema::hasTable($table)) {
                $this->line($table.' records: '.DB::table($table)->count());
            }
        }
        $roles = DB::table(config('permission.table_names.roles'))->where('name', 'super_admin')->where('guard_name', 'web')->whereNull('location_id')->pluck('id');
        $this->line('Global web super_admin roles: '.$roles->count().'; grants: '.DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id', $roles)->count());
        $this->line('Global super_admin assignments: '.DB::table(config('permission.table_names.model_has_roles'))->whereIn('role_id', $roles)->whereNull('location_id')->count());
        $this->info($this->option('inspect') ? 'Inspection complete; no changes made.' : 'Access synchronized; existing activation settings and grants preserved.');

        return self::SUCCESS;
    }
}
