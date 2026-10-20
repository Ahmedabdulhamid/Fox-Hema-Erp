<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseOrder> */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
            'order_number' => fake()->unique()->uuid(),
            'status' => 'draft',
            'order_date' => now()->toDateString(),
            'expected_date' => null,
            'subtotal' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
            'created_by' => User::factory(),
            'discount_amount' => '0.0000',
            'notes' => null,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ];
    }
}
