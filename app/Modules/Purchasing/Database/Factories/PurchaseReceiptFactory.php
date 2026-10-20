<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseReceipt> */
class PurchaseReceiptFactory extends Factory
{
    protected $model = PurchaseReceipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'location_id' => fn (array $attributes): int => PurchaseOrder::findOrFail($attributes['purchase_order_id'])->location_id,
            'receipt_number' => fake()->unique()->uuid(),
            'status' => 'draft',
            'received_at' => null,
            'received_by' => null,
            'posted_by' => null,
            'posted_at' => null,
            'notes' => null,
        ];
    }
}
