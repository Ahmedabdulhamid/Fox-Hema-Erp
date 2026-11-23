<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\User;
use Illuminate\Support\Facades\Lang;

class PermissionGroups
{
    /** Presentation data only; allowed IDs come from the existing delegation rules. */
    public function matrix(User $actor): array
    {
        $allowedGroups = $this->options($actor);
        $groups = Module::query()->orderBy('sort_order')->orderBy('name')->pluck('name')
            ->mapWithKeys(fn (string $name): array => [$this->groupLabel($name) => $allowedGroups[$name] ?? []])->all();
        $groups += collect($allowedGroups)->mapWithKeys(fn (array $permissions, string $group): array => [
            $this->groupLabel($group) => $permissions,
        ])->all();

        return collect($groups)->map(fn (array $permissions): array => collect($permissions)
            ->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name, 'label' => $this->label($name)])
            ->values()->all())->all();
    }

    public function localizedOptions(User $actor): array
    {
        return collect($this->options($actor))->mapWithKeys(fn (array $permissions, string $group): array => [
            $this->groupLabel($group) => array_map(fn (string $name): string => $this->label($name), $permissions),
        ])->all();
    }

    public function label(string $permission): string
    {
        $key = 'permissions.'.$permission;
        $locale = app()->getLocale();

        return Lang::has($key, $locale, false) ? Lang::get($key, [], $locale, false) : $permission;
    }

    public function groupLabel(string $group): string
    {
        $key = 'permissions_ui.groups.'.$group;
        $locale = app()->getLocale();

        return Lang::has($key, $locale, false) ? Lang::get($key, [], $locale, false) : __($group);
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
