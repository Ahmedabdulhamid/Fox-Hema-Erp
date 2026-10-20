<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Database\Factories\SalesReturnItemFactory;
use App\Modules\Sales\Models\Concerns\GuardsReturnWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturnItem extends Model
{
    use GuardsReturnWrites;

    /** @use HasFactory<SalesReturnItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_return_id',
        'sales_delivery_item_id',
        'sales_invoice_item_id',
        'variant_id',
        'quantity',
        'unit_price',
        'refund_amount',
        'disposition',
        'notes',
    ];

    protected static function newFactory(): SalesReturnItemFactory
    {
        return SalesReturnItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unbilled_quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'refund_amount' => 'decimal:4',
        ];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function salesDeliveryItem(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryItem::class, 'sales_delivery_item_id');
    }

    public function salesInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function creditNoteItems(): HasMany
    {
        return $this->hasMany(SalesCreditNoteItem::class, 'sales_return_item_id');
    }

    public function invoiceAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnInvoiceAllocation::class);
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnCostAllocation::class);
    }

    public function postedAllocations(): HasMany
    {
        return $this->hasMany(SalesReturnPostedAllocation::class);
    }
}
