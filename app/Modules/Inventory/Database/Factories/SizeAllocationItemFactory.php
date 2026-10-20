<?php

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SizeAllocationItem> */
class SizeAllocationItemFactory extends Factory
{
    protected $model = SizeAllocationItem::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory()->state(['size_id' => Size::factory()]),
            'allocation_id' => fn (array $attributes): int => SizeAllocation::factory()->create([
                'product_id' => ProductVariant::findOrFail($attributes['variant_id'])->product_id,
            ])->id,
            'quantity' => '1.0000',
        ];
    }
}
