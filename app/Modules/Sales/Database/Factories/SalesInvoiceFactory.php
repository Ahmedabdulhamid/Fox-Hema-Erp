<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesInvoice> */
class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_number' => fake()->unique()->uuid(),
            'sales_order_id' => null,
            'location_id' => fn (): int => Location::create(['name' => fake()->city(), 'type' => 'warehouse'])->getKey(),
            'customer_name' => null,
            'customer_phone' => null,
            'invoice_date' => now()->toDateString(),
            'due_date' => null,
            'status' => 'draft',
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
            'currency' => 'EGP',
            'posted_at' => null,
            'posted_by' => null,
            'created_by' => User::factory(),
            'notes' => null,
        ];
    }
}
