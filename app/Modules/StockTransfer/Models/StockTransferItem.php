<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\StockTransfer\Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferItem extends Model
{
    /** @use HasFactory<StockTransferItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'stock_transfer_id',
        'variant_id',
        'requested_quantity',
        'notes',
    ];

    protected static function newFactory(): StockTransferItemFactory
    {
        return StockTransferItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(StockTransferShipmentItem::class, 'stock_transfer_item_id');
    }
}
