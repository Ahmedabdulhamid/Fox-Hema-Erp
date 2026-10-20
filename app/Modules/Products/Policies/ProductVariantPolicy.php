<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\ProductsAccess;
use App\Modules\Products\Services\ProductVariantManager;

class ProductVariantPolicy
{
    public function viewAny(User $user): bool
    {
        return app(ProductsAccess::class)->allows($user, 'products.view');
    }

    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(ProductsAccess::class)->allows($user, 'products.variants.create');
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->view($user, $variant) && app(ProductsAccess::class)->allows($user, 'products.variants.update');
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->view($user, $variant) && app(ProductsAccess::class)->allows($user, 'products.variants.delete') && app(ProductVariantManager::class)->canDelete($variant);
    }

    public function viewReferenceCost(User $user, ProductVariant $variant): bool
    {
        return $this->view($user, $variant) && app(ProductsAccess::class)->referenceCost($user);
    }

    public function updateReferenceCost(User $user, ProductVariant $variant): bool
    {
        return $this->update($user, $variant) && $this->viewReferenceCost($user, $variant) && app(ProductsAccess::class)->allows($user, 'products.update_reference_cost');
    }

    public function updateSalePrice(User $user, ProductVariant $variant): bool
    {
        return $this->update($user, $variant) && app(ProductsAccess::class)->allows($user, 'products.update_prices');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
