<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Services\ProductCatalogManager;
use App\Modules\Products\Services\ProductsAccess;
use Illuminate\Database\Eloquent\Model;

class CatalogPolicy
{
    protected string $prefix = 'products';

    public function viewAny(User $user): bool
    {
        return app(ProductsAccess::class)->allows($user, $this->prefix.'.view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(ProductsAccess::class)->allows($user, $this->prefix.'.create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record) && app(ProductsAccess::class)->allows($user, $this->prefix.'.update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record) && app(ProductsAccess::class)->allows($user, $this->prefix.'.delete') && app(ProductCatalogManager::class)->canDelete($record);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }
}
