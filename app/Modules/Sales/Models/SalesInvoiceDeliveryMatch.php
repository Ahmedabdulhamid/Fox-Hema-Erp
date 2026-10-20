<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Sales\Database\Factories\SalesInvoiceDeliveryMatchFactory;
use App\Modules\Sales\Models\Concerns\GuardsInvoiceWrites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceDeliveryMatch extends Model
{
    use GuardsInvoiceWrites;

    /** @use HasFactory<SalesInvoiceDeliveryMatchFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_invoice_item_id',
        'sales_delivery_item_id',
        'matched_quantity',
    ];

    protected static function newFactory(): SalesInvoiceDeliveryMatchFactory
    {
        return SalesInvoiceDeliveryMatchFactory::new();
    }

    protected function casts(): array
    {
        return [
            'matched_quantity' => 'decimal:4',
        ];
    }

    public function salesInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }

    public function salesDeliveryItem(): BelongsTo
    {
        return $this->belongsTo(SalesDeliveryItem::class, 'sales_delivery_item_id');
    }
}
