<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorPayment> */
class VendorPaymentFactory extends Factory
{
    protected $model = VendorPayment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'payment_number' => fake()->unique()->uuid(),
            'payment_date' => now()->toDateString(),
            'amount' => '50.0000',
            'method' => 'cash',
            'external_reference' => null,
            'status' => 'draft',
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
            'voided_by' => null,
            'voided_at' => null,
            'notes' => null,
        ];
    }
}
