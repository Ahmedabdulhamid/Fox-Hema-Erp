<?php

namespace App\Modules\Taxes\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DefinitionAccess
{
    public function authorize(User $actor, string $module, string $operation): void
    {
        abort_unless(in_array($module, ['taxes', 'discounts'], true), 403);
        abort_unless($actor->exists && ! $actor->trashed() && User::whereKey($actor->id)->where('is_active', true)->exists(), 403);
        app(ModuleAccess::class)->authorize($actor, $module, $module.'.'.$operation);
        if (! $actor->isGlobalSuperAdmin()) {
            $location = getPermissionsTeamId();
            abort_unless($location !== null && Location::whereKey($location)->where('is_active', true)->exists()
                && $actor->locations()->whereKey($location)->exists(), 403);
        }
    }

    public function allows(?User $actor, string $module, string $operation): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorize($actor, $module, $operation);

            return true;
        } catch (HttpException|AuthorizationException $exception) {
            return false;
        }
    }
}
