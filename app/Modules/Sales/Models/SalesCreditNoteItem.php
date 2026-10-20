<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Sales\Database\Factories\SalesCreditNoteItemFactory;
use App\Modules\Sales\Models\Concerns\GuardsCreditWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCreditNoteItem extends Model
{
    use GuardsCreditWrites;

    /** @use HasFactory<SalesCreditNoteItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_credit_note_id',
        'sales_invoice_item_id',
        'sales_return_item_id',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
    ];

    protected static function newFactory(): SalesCreditNoteItemFactory
    {
        return SalesCreditNoteItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'commercial_snapshot' => 'array',
            'unit_price' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function salesCreditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class, 'sales_credit_note_id');
    }

    public function salesInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }

    public function salesReturnItem(): BelongsTo
    {
        return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id');
    }

    public function postedReturnAllocation(): BelongsTo
    {
        return $this->belongsTo(SalesReturnPostedAllocation::class, 'sales_return_posted_allocation_id');
    }
}
