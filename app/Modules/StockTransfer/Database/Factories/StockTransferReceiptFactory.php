<?php

namespace App\Modules\StockTransfer\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockTransferReceipt> */
class StockTransferReceiptFactory extends Factory
{
    protected $model = StockTransferReceipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stock_transfer_shipment_id' => StockTransferShipment::factory(),
            'stock_transfer_id' => fn (array $attributes): int => StockTransferShipment::findOrFail($attributes['stock_transfer_shipment_id'])->stock_transfer_id,
            'receipt_number' => fake()->unique()->uuid(),
            'status' => 'draft',
            'received_at' => null,
            'posted_at' => null,
            'received_by' => null,
            'posted_by' => null,
            'created_by' => User::factory(),
            'notes' => null,
        ];
    }
}
