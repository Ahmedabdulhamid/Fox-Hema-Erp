<?php

namespace App\Modules\Payments\Database\Factories;

use App\Modules\Payments\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('METHOD-########'),
            'name' => 'Approved cash method',
            'type' => 'cash',
            'is_active' => true,
            'requires_reference' => false,
            'available_in_pos' => false,
            'sort_order' => 0,
        ];
    }
}
