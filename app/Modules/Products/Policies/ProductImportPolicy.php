<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductImportAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductImportPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProductImportAccess::class)->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ProductImport $import): bool
    {
        try {
            app(ProductImportAccess::class)->authorize($user, $import);

            return true;
        } catch (AuthorizationException|HttpException $exception) {
            return false;
        }
    }

    public function update(User $user, ProductImport $import): bool
    {
        return false;
    }

    public function delete(User $user, ProductImport $import): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ProductImport $import): bool
    {
        return false;
    }

    public function forceDelete(User $user, ProductImport $import): bool
    {
        return false;
    }
}
