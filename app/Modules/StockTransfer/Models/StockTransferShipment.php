<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Database\Factories\StockTransferShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransferShipment extends Model
{
    /** @use HasFactory<StockTransferShipmentFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'shipment_number',
        'stock_transfer_id',
        'status',
        'dispatched_at',
        'dispatched_by',
        'created_by',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected static function newFactory(): StockTransferShipmentFactory
    {
        return StockTransferShipmentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferShipmentItem::class, 'stock_transfer_shipment_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'stock_transfer_shipment_id');
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(StockTransferReceiptItem::class, 'stock_transfer_shipment_id');
    }
}
