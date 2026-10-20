<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Database\Factories\PurchaseReceiptFactory;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseReceipt extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'GRN';

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            PurchaseOrder::whereKey($receipt->getOriginal('purchase_order_id'))->lockForUpdate()->firstOrFail();
            $stored = self::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($stored->status !== 'draft' || $receipt->isDirty(['purchase_order_id', 'location_id', 'receipt_number', 'uuid', 'preparation_key', 'backorder_of_id'])) {
                throw ValidationException::withMessages(['receipt' => __('Receipt identity and completed receipts are immutable.')]);
            }
            if (! in_array($receipt->status, ['draft', 'posted', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => __('Invalid receipt status.')]);
            }
            if ($receipt->status === 'posted') {
                $positive = 0;
                foreach ($stored->items()->with('stockMovement.inventoryCostEntry')->orderBy('id')->lockForUpdate()->get() as $item) {
                    if (BigDecimal::of($item->quantity)->isPositive()) {
                        $positive++;
                        $movement = $item->stockMovement;
                        $entry = $movement?->inventoryCostEntry;
                        if ($entry === null || $entry->purchase_value === null || $movement->reference_type !== $item->getMorphClass()
                            || (int) $movement->reference_id !== (int) $item->id || $movement->quantity !== $item->quantity
                            || (int) $movement->location_id !== (int) $receipt->location_id
                            || (int) $movement->variant_id !== (int) $item->purchaseOrderItem->variant_id) {
                            throw ValidationException::withMessages(['receipt' => __('Receipt posting requires stock movements and inventory valuation.')]);
                        }
                    }
                }
                if ($positive === 0 || $receipt->posted_at === null || $receipt->posted_by === null) {
                    throw ValidationException::withMessages(['receipt' => __('Receipt posting requires positive quantities and validation audit information.')]);
                }
            }
        });
        static::deleting(function (self $receipt): void {
            throw ValidationException::withMessages(['receipt' => __('Cancel draft receipts instead of deleting receipt history.')]);
        });
    }

    /** @use HasFactory<PurchaseReceiptFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'purchase_order_id',
        'location_id',
        'receipt_number',
        'status',
        'received_at',
        'received_by',
        'posted_at',
        'posted_by',
        'notes',
    ];

    protected static function newFactory(): PurchaseReceiptFactory
    {
        return PurchaseReceiptFactory::new();
    }

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'purchase_receipt_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function backorderOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'backorder_of_id');
    }

    public function backorders(): HasMany
    {
        return $this->hasMany(self::class, 'backorder_of_id');
    }
}
