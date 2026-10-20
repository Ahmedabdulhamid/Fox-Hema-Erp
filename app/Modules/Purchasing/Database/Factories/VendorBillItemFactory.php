<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorBillItem> */
class VendorBillItemFactory extends Factory
{
    protected $model = VendorBillItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vendor_bill_id' => VendorBill::factory(),
            'variant_id' => null,
            'description' => fake()->sentence(),
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
            'subtotal' => '50.0000',
            'discount_amount' => '0.0000',
        ];
    }
}
