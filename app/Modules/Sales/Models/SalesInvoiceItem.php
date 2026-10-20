<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Database\Factories\SalesInvoiceItemFactory;
use App\Modules\Sales\Models\Concerns\GuardsInvoiceWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoiceItem extends Model
{
    use GuardsInvoiceWrites;

    /** @use HasFactory<SalesInvoiceItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_invoice_id',
        'sales_order_item_id',
        'variant_id',
        'description',
        'line_type',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'commercial_snapshot',
    ];

    protected static function newFactory(): SalesInvoiceItemFactory
    {
        return SalesInvoiceItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function deliveryMatches(): HasMany
    {
        return $this->hasMany(SalesInvoiceDeliveryMatch::class, 'sales_invoice_item_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_invoice_item_id');
    }

    public function creditNoteItems(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class, 'sales_invoice_item_id');
    }
}
