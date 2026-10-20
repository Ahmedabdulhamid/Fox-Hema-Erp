<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorPaymentAllocation> */
class VendorPaymentAllocationFactory extends Factory
{
    protected $model = VendorPaymentAllocation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'vendor_payment_id' => VendorPayment::factory(),
            'vendor_bill_id' => fn (array $attributes): int => VendorBill::factory()->create([
                'supplier_id' => VendorPayment::findOrFail($attributes['vendor_payment_id'])->supplier_id,
            ])->getKey(),
            'amount' => '50.0000',
            'supplier_id' => fn (array $attributes): int => VendorPayment::findOrFail($attributes['vendor_payment_id'])->supplier_id,
        ];
    }
}
