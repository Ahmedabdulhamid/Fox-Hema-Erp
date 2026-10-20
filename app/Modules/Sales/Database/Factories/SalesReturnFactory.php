<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesReturn> */
class SalesReturnFactory extends Factory
{
    protected $model = SalesReturn::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'return_number' => fake()->unique()->uuid(),
            'sales_order_id' => null,
            'sales_invoice_id' => null,
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
            'status' => 'draft',
            'return_date' => now()->toDateString(),
            'reason' => null,
            'notes' => null,
            'created_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
            'posted_by' => null,
            'posted_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ];
    }
}
