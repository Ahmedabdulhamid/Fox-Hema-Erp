<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferShipment> */
class StockTransferShipmentFactory extends Factory
{
    protected $model = StockTransferShipment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'shipment_number' => fake()->unique()->uuid(),
            'stock_transfer_id' => StockTransfer::factory(),
            'status' => 'draft',
            'dispatched_at' => null,
            'dispatched_by' => null,
            'created_by' => User::factory(),
            'notes' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ];
    }
}
