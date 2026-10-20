<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;

class PermissionGroups
{
    /** Presentation data only; allowed IDs come from the existing delegation rules. */
    public function matrix(User $actor): array
    {
        $allowedGroups = $this->options($actor);
        $groups = Module::query()->orderBy('sort_order')->orderBy('name')->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$name => $allowedGroups[$name] ?? []])->all();

        return collect($groups + $allowedGroups)->map(fn (array $permissions): array => collect($permissions)
            ->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name, 'label' => __($name)])
            ->values()->all())->all();
    }

    public function localizedOptions(User $actor): array
    {
        return collect($this->options($actor))->mapWithKeys(fn (array $permissions, string $group): array => [
            __($group) => array_map(fn (string $name): string => __($name), $permissions),
        ])->all();
    }

    public function options(User $actor): array
    {
        $allowed = Permission::query()->where('guard_name', 'web')->orderBy('name')
            ->when(! $actor->isGlobalSuperAdmin(), fn ($query) => $query->whereIn('id', $actor->getAllPermissions()->pluck('id')))
            ->get()->keyBy('id');
        $groups = [];
        $seen = [];
        foreach (Module::query()->with('permissions')->orderBy('sort_order')->orderBy('name')->get() as $module) {
            foreach ($module->permissions as $permission) {
                if ($allowed->has($permission->id)) {
                    $groups[$module->name][$permission->id] = $permission->name;
                    $seen[] = $permission->id;
                }
            }
        }
        foreach ($allowed->except($seen) as $permission) {
            $groups['Unmapped historical permissions'][$permission->id] = $permission->name;
        }

        return $groups;
    }
}
