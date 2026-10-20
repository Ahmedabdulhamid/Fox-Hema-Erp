<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Models\StockTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransfer> */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transfer_number' => fake()->unique()->uuid(),
            'from_location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
            'to_location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'branch'])->getKey(),
            'status' => 'draft',
            'requested_at' => null,
            'approved_at' => null,
            'closed_at' => null,
            'created_by' => User::factory(),
            'approved_by' => null,
            'closed_by' => null,
            'notes' => null,
        ];
    }
}
