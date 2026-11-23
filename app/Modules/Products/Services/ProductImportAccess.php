<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Products\Models\ProductImport;
use Closure;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

class ProductImportAccess
{
    public function authorize(User $actor, ?ProductImport $import = null): void
    {
        foreach (['products.view', 'products.create', 'products.import'] as $permission) {
            app(ProductsAccess::class)->authorize($actor, $permission);
        }
        abort_unless(config('product-import.enabled'), 403);
        if ($import !== null) {
            abort_unless($actor->isGlobalSuperAdmin() || ((int) $import->actor_id === (int) $actor->id
                && ! $import->global_scope && $import->location_id !== null && (int) $import->location_id === (int) getPermissionsTeamId()), 403);
        }
    }

    public function allows(?User $actor): bool
    {
        return $actor !== null && config('product-import.enabled')
            && collect(['products.view', 'products.create', 'products.import'])->every(fn (string $permission): bool => app(ProductsAccess::class)->allows($actor, $permission));
    }

    /** Reload credentials and restore process-local context even after failure. */
    public function asInitiator(ProductImport $import, Closure $operation): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();
        $previousActor = Auth::guard('web')->user();
        try {
            RequestAuthorizationFacts::forget();
            $registrar->setPermissionsTeamId($import->location_id);
            $registrar->forgetCachedPermissions();
            $actor = User::find($import->actor_id);
            abort_unless($actor !== null, 403);
            abort_if(($import->global_scope && ! $actor->isGlobalSuperAdmin()) || (! $import->global_scope && $import->location_id === null), 403);
            Auth::guard('web')->setUser($actor);
            $this->authorize($actor, $import);

            return $operation($actor);
        } finally {
            RequestAuthorizationFacts::forget();
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
            Auth::guard('web')->forgetUser();
            if ($previousActor !== null) {
                $previousActor->unsetRelation('roles')->unsetRelation('permissions');
                Auth::guard('web')->setUser($previousActor);
            }
        }
    }
}
