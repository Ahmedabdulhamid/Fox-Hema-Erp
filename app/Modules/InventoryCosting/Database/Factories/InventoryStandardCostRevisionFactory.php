<?php

namespace App\Modules\InventoryCosting\Database\Factories;

use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryStandardCostRevision> */
class InventoryStandardCostRevisionFactory extends Factory
{
    protected $model = InventoryStandardCostRevision::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'standard_cost_id' => InventoryStandardCost::factory(),
            'old_cost' => null,
            'new_cost' => '5.000000',
            'reason' => null,
            'changed_by' => null,
            'effective_at' => now(),
        ];
    }
}
