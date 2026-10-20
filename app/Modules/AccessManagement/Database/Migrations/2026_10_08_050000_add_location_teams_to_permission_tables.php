<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $key = config('permission.column_names.team_foreign_key', 'location_id');

        if (! Schema::hasColumn($tables['roles'], $key)) {
            Schema::table($tables['roles'], function (Blueprint $table) use ($key) {
                $table->foreignId($key)->nullable()->after('id')->constrained('locations')->restrictOnDelete();
            });
        }

        foreach ([$tables['model_has_roles'], $tables['model_has_permissions']] as $tableName) {
            if (! Schema::hasColumn($tableName, $key)) {
                Schema::table($tableName, function (Blueprint $table) use ($key) {
                    $table->foreignId($key)->nullable()->first()->constrained('locations')->cascadeOnDelete();
                });
            }
        }

        if (DB::getDriverName() === 'mysql') {
            $this->upgradeMysqlIndexes($tables, $key);
        }
    }

    private function upgradeMysqlIndexes(array $tables, string $key): void
    {
        $database = DB::getDatabaseName();
        $rolesUnique = $this->uniqueIndexOn($database, $tables['roles'], ['name', 'guard_name']);
        if ($rolesUnique !== null) {
            DB::statement("ALTER TABLE `{$tables['roles']}` DROP INDEX `{$rolesUnique}`");
        }
        if ($this->uniqueIndexOn($database, $tables['roles'], [$key, 'name', 'guard_name']) === null) {
            DB::statement("ALTER TABLE `{$tables['roles']}` ADD UNIQUE `roles_location_name_guard_unique` (`{$key}`, `name`, `guard_name`)");
        }

        $definitions = [
            $tables['model_has_roles'] => ['role_id', 'model_id', 'model_type'],
            $tables['model_has_permissions'] => ['permission_id', 'model_id', 'model_type'],
        ];
        foreach ($definitions as $table => $oldColumns) {
            $supportIndex = $table.'_'.$oldColumns[0].'_foreign_support_index';
            if (! $this->indexExists($database, $table, $supportIndex)) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$supportIndex}` (`{$oldColumns[0]}`)");
            }

            $newColumns = array_merge([$key], $oldColumns);
            if ($this->uniqueIndexOn($database, $table, $newColumns) === null) {
                $name = $table.'_location_assignment_unique';
                $quoted = implode(', ', array_map(fn ($column) => "`{$column}`", $newColumns));
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$name}` ({$quoted})");
            }

            $primary = $this->primaryColumns($database, $table);
            if ($primary === $oldColumns) {
                DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY");
            }
        }
    }

    private function uniqueIndexOn(string $database, string $table, array $columns): ?string
    {
        $indexes = DB::table('information_schema.statistics')
            ->selectRaw('INDEX_NAME AS index_name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS seq_in_index')
            ->where('table_schema', $database)->where('table_name', $table)->where('non_unique', 0)
            ->orderBy('index_name')->orderBy('seq_in_index')->get()->groupBy('index_name');

        foreach ($indexes as $name => $parts) {
            if ($parts->pluck('column_name')->all() === $columns) {
                return (string) $name;
            }
        }

        return null;
    }

    private function primaryColumns(string $database, string $table): array
    {
        return DB::table('information_schema.statistics')->selectRaw('COLUMN_NAME AS column_name')->where('table_schema', $database)
            ->where('table_name', $table)->where('index_name', 'PRIMARY')->orderBy('seq_in_index')
            ->pluck('column_name')->all();
    }

    private function indexExists(string $database, string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function down(): void
    {
        // Intentionally irreversible: removing team columns can destroy authorization scope data.
    }
};
