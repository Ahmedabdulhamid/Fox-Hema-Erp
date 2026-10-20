<?php

namespace App\Modules\Products\Services;

class ProductPermissions
{
    public static function all(): array
    {
        $permissions = [];
        foreach (['products', 'products.brands', 'products.categories', 'products.sizes', 'products.colors'] as $prefix) {
            foreach (['view', 'create', 'update', 'delete'] as $operation) {
                $permissions[] = $prefix.'.'.$operation;
            }
        }
        foreach (['variants', 'images'] as $relation) {
            foreach (['create', 'update', 'delete'] as $operation) {
                $permissions[] = 'products.'.$relation.'.'.$operation;
            }
        }

        return [...$permissions, 'products.update_prices', 'products.view_reference_cost', 'products.update_reference_cost', 'products.assign_taxes', 'products.assign_discounts', 'products.categories.view_costing', 'products.categories.update_costing'];
    }
}
