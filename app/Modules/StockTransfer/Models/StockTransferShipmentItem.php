<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\StockTransfer\Database\Factories\StockTransferShipmentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferShipmentItem extends Model
{
    /** @use HasFactory<StockTransferShipmentItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'stock_transfer_id',
        'stock_transfer_shipment_id',
        'stock_transfer_item_id',
        'shipped_quantity',
    ];

    protected static function newFactory(): StockTransferShipmentItemFactory
    {
        return StockTransferShipmentItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'shipped_quantity' => 'decimal:4',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function stockTransferShipment(): BelongsTo
    {
        return $this->belongsTo(StockTransferShipment::class, 'stock_transfer_shipment_id');
    }

    public function stockTransferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class, 'stock_transfer_shipment_item_id');
    }
}
