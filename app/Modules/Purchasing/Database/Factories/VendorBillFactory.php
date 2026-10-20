<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorBill> */
class VendorBillFactory extends Factory
{
    protected $model = VendorBill::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'purchase_order_id' => null,
            'bill_number' => fake()->unique()->uuid(),
            'bill_date' => now()->toDateString(),
            'due_date' => null,
            'status' => 'draft',
            'subtotal' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
            'discount_amount' => '0.0000',
            'notes' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }
}
