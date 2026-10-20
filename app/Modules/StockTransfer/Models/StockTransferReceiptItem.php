<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\StockTransfer\Database\Factories\StockTransferReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferReceiptItem extends Model
{
    /** @use HasFactory<StockTransferReceiptItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'stock_transfer_receipt_id',
        'stock_transfer_shipment_id',
        'stock_transfer_shipment_item_id',
        'received_quantity',
        'damaged_quantity',
        'notes',
    ];

    protected static function newFactory(): StockTransferReceiptItemFactory
    {
        return StockTransferReceiptItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'received_quantity' => 'decimal:4',
            'damaged_quantity' => 'decimal:4',
        ];
    }

    public function stockTransferReceipt(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceipt::class, 'stock_transfer_receipt_id');
    }

    public function stockTransferShipment(): BelongsTo
    {
        return $this->belongsTo(StockTransferShipment::class, 'stock_transfer_shipment_id');
    }

    public function stockTransferShipmentItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferShipmentItem::class, 'stock_transfer_shipment_item_id');
    }
}
