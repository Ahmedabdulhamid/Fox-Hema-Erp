<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesCreditNote> */
class SalesCreditNoteFactory extends Factory
{
    protected $model = SalesCreditNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'credit_note_number' => fake()->unique()->uuid(),
            'sales_invoice_id' => SalesInvoice::factory(),
            'sales_return_id' => null,
            'credit_date' => now()->toDateString(),
            'status' => 'draft',
            'subtotal' => '0.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'total_amount' => '0.0000',
            'reason' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
        ];
    }
}
