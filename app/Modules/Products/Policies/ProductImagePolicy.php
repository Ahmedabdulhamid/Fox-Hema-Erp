<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Services\ProductsAccess;

class ProductImagePolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProductsAccess::class)->allows($user, 'products.view');
    }

    public function view(User $user, ProductImage $image): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(ProductsAccess::class)->allows($user, 'products.images.create');
    }

    public function update(User $user, ProductImage $image): bool
    {
        return $this->view($user, $image) && app(ProductsAccess::class)->allows($user, 'products.images.update');
    }

    public function delete(User $user, ProductImage $image): bool
    {
        return $this->view($user, $image) && app(ProductsAccess::class)->allows($user, 'products.images.delete');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
