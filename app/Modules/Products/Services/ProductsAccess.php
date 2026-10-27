<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductsAccess
{
    public function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->exists && ! $actor->trashed() && RequestAuthorizationFacts::actorIsActive($actor), 403);
        app(ModuleAccess::class)->authorize($actor, 'products', $permission);
        if (! $actor->isGlobalSuperAdmin()) {
            $location = getPermissionsTeamId();
            abort_unless($location !== null && RequestAuthorizationFacts::locationIsActive((int) $location)
                && RequestAuthorizationFacts::actorHasLocation($actor, (int) $location), 403);
        }
    }

    public function allows(?User $actor, string $permission): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorize($actor, $permission);

            return true;
        } catch (HttpException|AuthorizationException $exception) {
            return false;
        }
    }

    public function referenceCost(?User $actor): bool
    {
        return $this->allows($actor, 'products.view') && $this->allows($actor, 'products.view_reference_cost');
    }
}
