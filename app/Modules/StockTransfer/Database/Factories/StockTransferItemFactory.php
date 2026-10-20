<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferItem> */
class StockTransferItemFactory extends Factory
{
    protected $model = StockTransferItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'variant_id' => fn (): int => ProductVariant::create([
                'product_id' => Product::create(['name' => fake()->words(3, true)])->getKey(),
                'sku' => fake()->unique()->uuid(),
            ])->getKey(),
            'requested_quantity' => '10.0000',
            'notes' => null,
        ];
    }
}
