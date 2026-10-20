<?php

namespace App\Modules\StockTransfer\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'TRF';

    /** @use HasFactory<StockTransferFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'transfer_number',
        'from_location_id',
        'to_location_id',
        'status',
        'requested_at',
        'approved_at',
        'closed_at',
        'created_by',
        'approved_by',
        'closed_by',
        'notes',
    ];

    protected static function newFactory(): StockTransferFactory
    {
        return StockTransferFactory::new();
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(StockTransferShipment::class, 'stock_transfer_id');
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(StockTransferShipmentItem::class, 'stock_transfer_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'stock_transfer_id');
    }
}
