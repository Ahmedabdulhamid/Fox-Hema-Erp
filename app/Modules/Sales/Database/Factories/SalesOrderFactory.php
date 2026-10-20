<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesOrder> */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'order_number' => fake()->unique()->uuid(),
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
            'channel' => 'manual',
            'external_order_id' => null,
            'customer_name' => null,
            'customer_phone' => null,
            'status' => 'draft',
            'fulfillment_status' => 'unfulfilled',
            'ordered_at' => now(),
            'confirmed_at' => null,
            'confirmed_by' => null,
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
