<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Database\Factories\PurchaseReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptItem extends Model
{
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $orderIds = array_unique(array_filter([$item->purchase_order_id, $item->getOriginal('purchase_order_id')]));
            PurchaseOrder::whereKey($orderIds)->orderBy('id')->lockForUpdate()->get();
            $ids = array_unique(array_filter([$item->purchase_receipt_id, $item->getOriginal('purchase_receipt_id')]));
            foreach (PurchaseReceipt::whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $receipt) {
                if ($receipt->status !== 'draft') {
                    throw ValidationException::withMessages(['items' => __('Lines of completed receipts are immutable.')]);
                }
            }
            if ($item->exists && $item->isDirty(['purchase_order_id', 'purchase_receipt_id', 'purchase_order_item_id', 'expected_quantity', 'uuid'])) {
                throw ValidationException::withMessages(['items' => __('Expected receipt line identities are immutable.')]);
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** @use HasFactory<PurchaseReceiptItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'purchase_order_id',
        'purchase_receipt_id',
        'purchase_order_item_id',
        'quantity',
        'unit_cost',
        'stock_movement_id',
        'expected_quantity',
    ];

    protected static function newFactory(): PurchaseReceiptItemFactory
    {
        return PurchaseReceiptItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'expected_quantity' => 'decimal:4',
        ];
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function billReceiptMatches(): HasMany
    {
        return $this->hasMany(VendorBillReceiptMatch::class, 'purchase_receipt_item_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
