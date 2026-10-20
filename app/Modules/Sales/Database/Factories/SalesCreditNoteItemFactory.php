<?php

namespace App\Modules\Sales\Database\Factories;

use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNoteItem;
use App\Modules\Sales\Models\SalesInvoiceItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesCreditNoteItem> */
class SalesCreditNoteItemFactory extends Factory
{
    protected $model = SalesCreditNoteItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sales_invoice_item_id' => SalesInvoiceItem::factory(),
            'sales_credit_note_id' => fn (array $attributes): int => SalesCreditNote::factory()->create([
                'sales_invoice_id' => SalesInvoiceItem::findOrFail($attributes['sales_invoice_item_id'])->sales_invoice_id,
            ])->getKey(),
            'sales_return_item_id' => null,
            'quantity' => '10.0000',
            'unit_price' => '5.000000',
            'subtotal' => '50.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'line_total' => '50.0000',
        ];
    }
}
