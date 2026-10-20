<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesDelivery> */
class SalesDeliveryFactory extends Factory
{
    protected $model = SalesDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'delivery_number' => fake()->unique()->uuid(),
            'sales_order_id' => SalesOrder::factory(),
            'location_id' => fn (array $attributes): int => SalesOrder::findOrFail($attributes['sales_order_id'])->location_id,
            'status' => 'draft',
            'delivered_at' => null,
            'posted_at' => null,
            'posted_by' => null,
            'created_by' => User::factory(),
            'notes' => null,
        ];
    }
}
