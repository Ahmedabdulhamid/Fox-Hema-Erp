<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\DB;

final class BackupAccess
{
    private const REQUEST_CACHE_KEY = 'backup_restore.access.decisions';

    public function allows(?User $user, string $permission): bool
    {
        if ($user === null || ! in_array($permission, BackupPermissions::all(), true)) {
            return false;
        }

        $request = request();
        if (! $request->hasSession()) {
            return $this->hasPermission($user, $permission);
        }

        $decisions = $request->attributes->get(self::REQUEST_CACHE_KEY, []);
        $cacheKey = $user->getMorphClass().':'.$user->getKey().':'.$permission;
        if (is_array($decisions) && array_key_exists($cacheKey, $decisions) && is_bool($decisions[$cacheKey])) {
            return $decisions[$cacheKey];
        }

        $decisions = is_array($decisions) ? $decisions : [];
        foreach ($this->permissionDecisions($user) as $name => $allowed) {
            $decisions[$user->getMorphClass().':'.$user->getKey().':'.$name] = $allowed;
        }
        $request->attributes->set(self::REQUEST_CACHE_KEY, $decisions);

        return $decisions[$cacheKey] ?? false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (! User::query()->whereKey($user->getKey())->where('is_active', true)->exists()
            || ! Module::query()->where('code', 'backup_restore')->where('is_active', true)
                ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $permission)->where('guard_name', 'web'))->exists()) {
            return false;
        }

        $tables = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key', 'location_id');
        $permissions = DB::table($tables['permissions'])->where('name', $permission)->where('guard_name', 'web')->select('id');
        $direct = DB::table($tables['model_has_permissions'])->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())->whereNull($team)->whereIn('permission_id', $permissions)->exists();
        $role = DB::table($tables['model_has_roles'].' as assignments')
            ->join($tables['roles'].' as roles', 'roles.id', '=', 'assignments.role_id')
            ->join($tables['role_has_permissions'].' as grants', 'grants.role_id', '=', 'roles.id')
            ->where('assignments.model_type', $user->getMorphClass())->where('assignments.model_id', $user->getKey())
            ->whereNull('assignments.'.$team)->whereNull('roles.'.$team)->where('roles.guard_name', 'web')
            ->whereIn('grants.permission_id', $permissions)->exists();

        return $direct || $role;
    }

    /** @return array<string, bool> */
    private function permissionDecisions(User $user): array
    {
        $permissionNames = BackupPermissions::all();
        $decisions = array_fill_keys($permissionNames, false);
        if (! User::query()->whereKey($user->getKey())->where('is_active', true)->exists()) {
            return $decisions;
        }

        $tables = config('permission.table_names');
        $team = config('permission.column_names.team_foreign_key', 'location_id');
        $available = DB::table('modules as modules')
            ->join('modules_permissions as module_permissions', 'module_permissions.module_id', '=', 'modules.id')
            ->join($tables['permissions'].' as permissions', 'permissions.id', '=', 'module_permissions.permission_id')
            ->where('modules.code', 'backup_restore')->where('modules.is_active', true)
            ->where('permissions.guard_name', 'web')->whereIn('permissions.name', $permissionNames)
            ->pluck('permissions.name')->all();
        if ($available === []) {
            return $decisions;
        }

        $direct = DB::table($tables['model_has_permissions'].' as assignments')
            ->join($tables['permissions'].' as permissions', 'permissions.id', '=', 'assignments.permission_id')
            ->where('assignments.model_type', $user->getMorphClass())->where('assignments.model_id', $user->getKey())
            ->whereNull('assignments.'.$team)->where('permissions.guard_name', 'web')->whereIn('permissions.name', $available)
            ->pluck('permissions.name')->all();
        $roles = DB::table($tables['model_has_roles'].' as assignments')
            ->join($tables['roles'].' as roles', 'roles.id', '=', 'assignments.role_id')
            ->join($tables['role_has_permissions'].' as grants', 'grants.role_id', '=', 'roles.id')
            ->join($tables['permissions'].' as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('assignments.model_type', $user->getMorphClass())->where('assignments.model_id', $user->getKey())
            ->whereNull('assignments.'.$team)->whereNull('roles.'.$team)->where('roles.guard_name', 'web')
            ->where('permissions.guard_name', 'web')->whereIn('permissions.name', $available)
            ->pluck('permissions.name')->all();

        foreach (array_unique([...$direct, ...$roles]) as $name) {
            $decisions[$name] = true;
        }

        return $decisions;
    }

    public function allowsPanel(?User $user): bool
    {
        return $this->allows($user, BackupPermissions::VIEW) || $this->allows($user, BackupPermissions::SETTINGS);
    }

    public function authorize(?User $user, string $permission): void
    {
        abort_unless($this->allows($user, $permission), 403);
    }
}
