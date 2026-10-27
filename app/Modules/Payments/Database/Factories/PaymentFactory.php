<?php

namespace App\Modules\Payments\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_number' => 'TEST-'.Str::uuid7(),
            'idempotency_key' => (string) Str::uuid7(),
            'location_id' => fn (): int => Location::create(['name' => 'Payment factory branch', 'type' => 'branch', 'is_active' => true])->id,
            'payment_method_id' => PaymentMethodFactory::new(),
            'direction' => 'in',
            'status' => 'draft',
            'amount' => '10.0000',
            'created_by' => User::factory(),
        ];
    }
}
