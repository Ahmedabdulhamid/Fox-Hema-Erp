<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseOrderItem extends Model
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
            $ids = array_unique(array_filter([$item->purchase_order_id, $item->getOriginal('purchase_order_id')]));
            foreach (PurchaseOrder::whereKey($ids)->orderBy('id')->lockForUpdate()->get() as $order) {
                if (! in_array($order->status, ['draft', 'rfq'], true)) {
                    throw ValidationException::withMessages(['record' => __('Lines of confirmed or cancelled purchase orders are immutable.')]);
                }
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** @use HasFactory<PurchaseOrderItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'purchase_order_id',
        'variant_id',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'commercial_snapshot',
        'notes',
    ];

    protected static function newFactory(): PurchaseOrderItemFactory
    {
        return PurchaseOrderItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'subtotal' => 'decimal:4',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class, 'purchase_order_item_id');
    }
}
