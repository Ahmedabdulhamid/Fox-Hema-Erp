<?php

namespace App\Modules\Inventory\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SizeAllocation> */
class SizeAllocationFactory extends Factory
{
    protected $model = SizeAllocation::class;

    public function definition(): array
    {
        return [
            'reference' => 'SA-'.Str::uuid7(), 'submission_key' => hash('sha256', (string) Str::uuid7()),
            'submission_hash' => hash('sha256', (string) Str::uuid7()), 'status' => 'draft',
            'product_id' => Product::factory(),
            'source_variant_id' => function (array $attributes): int {
                $existing = ProductVariant::where('unallocated_product_id', $attributes['product_id'])->first();
                if ($existing !== null) {
                    return $existing->id;
                }
                $source = new ProductVariant(['product_id' => $attributes['product_id']]);
                $source->unallocated_product_id = $attributes['product_id'];
                $source->save();

                return $source->id;
            },
            'location_id' => fn (): int => Location::create(['name' => fake()->unique()->city(), 'type' => 'warehouse', 'is_active' => true])->id,
            'created_by' => User::factory(),
        ];
    }
}
