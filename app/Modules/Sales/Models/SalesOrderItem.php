<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Database\Factories\SalesOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderItem extends Model
{
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $this->guardWrite();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            ModuleRegistryLock::acquire();
            $this->guardWrite();

            return parent::delete();
        });
    }

    private function guardWrite(): void
    {
        $item = $this;
        $parent = $item->getOriginal('sales_order_id') ?? $item->sales_order_id;
        $order = SalesOrder::whereKey($parent)->lockForUpdate()->firstOrFail();
        if ($order->status !== 'draft' || $order->fulfillment_status !== 'unfulfilled'
            || $order->confirmation_payload_hash !== null || $order->cancellation_payload_hash !== null
            || $order->confirmed_at !== null || $order->cancelled_at !== null
            || ($item->exists && $item->isDirty('sales_order_id'))) {
            throw ValidationException::withMessages(['record' => __('Only lines belonging to unfulfilled draft sales orders can be changed.')]);
        }
    }

    /** @use HasFactory<SalesOrderItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_order_id',
        'variant_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'line_total',
        'commercial_snapshot',
        'notes',
    ];

    protected static function newFactory(): SalesOrderItemFactory
    {
        return SalesOrderItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(SalesDeliveryItem::class, 'sales_order_item_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'sales_order_item_id');
    }
}
