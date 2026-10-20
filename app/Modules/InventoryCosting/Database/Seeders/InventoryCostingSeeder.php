<?php

namespace App\Modules\InventoryCosting\Database\Seeders;

use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use Illuminate\Database\Seeder;

class InventoryCostingSeeder extends Seeder
{
    public function run(): void
    {
        Category::query()->each(function (Category $category): void {
            InventoryValuationSetting::firstOrCreate(
                ['category_id' => $category->id],
                ['costing_method' => 'avco'],
            );
        });
    }
}
