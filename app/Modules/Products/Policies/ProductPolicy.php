<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductAssignmentManager;

class ProductPolicy extends CatalogPolicy
{
    protected string $prefix = 'products';

    public function assignTaxes(User $user, Product $product): bool
    {
        return $this->view($user, $product) && app(ProductAssignmentManager::class)->canAssign($user, 'taxes');
    }

    public function assignDiscounts(User $user, Product $product): bool
    {
        return $this->view($user, $product) && app(ProductAssignmentManager::class)->canAssign($user, 'discounts');
    }
}
