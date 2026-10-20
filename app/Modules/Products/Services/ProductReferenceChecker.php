<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductReferenceChecker
{
    public const VARIANT_TABLES = ['stock_balances', 'stock_movements', 'inventory_cost_states', 'inventory_standard_costs', 'fifo_layers', 'purchase_order_items', 'vendor_bill_items', 'sales_order_items', 'sales_invoice_items', 'sales_return_items', 'stock_transfer_items', 'inventory_size_allocation_items'];

    public function variantIsReferenced(ProductVariant $variant): bool
    {
        if (Schema::hasTable('inventory_size_allocations') && DB::table('inventory_size_allocations')->where('source_variant_id', $variant->id)->exists()) {
            return true;
        }
        foreach (self::VARIANT_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('variant_id', $variant->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    public function productIsReferenced(Product $product): bool
    {
        foreach ($product->variants()->get() as $variant) {
            if ($this->variantIsReferenced($variant)) {
                return true;
            }
        }

        return false;
    }

    public function categoryIsReferenced(Category $category): bool
    {
        $productIds = $category->products()->select('products.id');
        $variantIds = ProductVariant::query()->select('product_variants.id')->whereIn('product_id', $productIds);

        foreach (self::VARIANT_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('variant_id', clone $variantIds)->exists()) {
                return true;
            }
        }

        return false;
    }
}
