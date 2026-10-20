<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Database\Factories\StockTransferReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferReceipt extends Model
{
    /** @use HasFactory<StockTransferReceiptFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'receipt_number',
        'stock_transfer_id',
        'stock_transfer_shipment_id',
        'status',
        'received_at',
        'posted_at',
        'received_by',
        'posted_by',
        'created_by',
        'notes',
    ];

    protected static function newFactory(): StockTransferReceiptFactory
    {
        return StockTransferReceiptFactory::new();
    }

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'posted_at' => 'datetime',
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

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class, 'stock_transfer_receipt_id');
    }
}
