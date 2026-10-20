<?php

namespace App\Modules\Products\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Models\Category;

class CategoryPolicy extends CatalogPolicy
{
    protected string $prefix = 'products.categories';

    public function viewCosting(User $user, Category $category): bool
    {
        return app(CategoryCostingManager::class)->canView($user);
    }

    public function updateCosting(User $user, Category $category): bool
    {
        return $this->update($user, $category) && app(CategoryCostingManager::class)->canUpdate($user);
    }
}
