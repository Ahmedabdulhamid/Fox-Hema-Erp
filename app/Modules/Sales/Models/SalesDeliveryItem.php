<?php

namespace App\Modules\Sales\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Database\Factories\SalesDeliveryItemFactory;
use App\Modules\Sales\Services\SalesFulfillmentService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesDeliveryItem extends Model
{
    public function save(array $options = []): bool
    {
        return DB::transaction(function () use ($options): bool {
            $this->guardWrite();
            $quantity = app(PurchaseOrderCalculator::class)->decimal($this->quantity, 'quantity', 18, 4);
            $order = SalesOrder::findOrFail($this->sales_order_id);
            $remaining = app(SalesFulfillmentService::class)->quantities($order, true)[$this->sales_order_item_id]['remaining'] ?? '0';
            $other = $this->newQuery()->where('sales_delivery_id', $this->sales_delivery_id)
                ->where('sales_order_item_id', $this->sales_order_item_id)->when($this->exists, fn ($query) => $query->whereKeyNot($this->id))->exists();
            if (! BigDecimal::of($quantity)->isPositive() || BigDecimal::of($quantity)->isGreaterThan($remaining) || $other) {
                throw ValidationException::withMessages(['record' => __('Delivery quantity must be positive and cannot exceed the remaining order quantity.')]);
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $this->guardWrite();

            return parent::delete();
        });
    }

    private function guardWrite(): void
    {
        ModuleRegistryLock::acquire();
        $order = SalesOrder::whereKey($this->getOriginal('sales_order_id') ?? $this->sales_order_id)->lockForUpdate()->firstOrFail();
        $delivery = $order->deliveries()->whereKey($this->getOriginal('sales_delivery_id') ?? $this->sales_delivery_id)->lockForUpdate()->firstOrFail();
        $stored = $this->exists ? self::whereKey($this->id)->lockForUpdate()->firstOrFail() : null;
        if ($delivery->status !== 'draft' || $delivery->posted_at !== null || $delivery->posted_by !== null || $delivery->cancelled_at !== null
            || $delivery->cancelled_by !== null || $delivery->cancellation_payload_hash !== null
            || $delivery->posting_payload_hash !== null || $delivery->posting_evidence_hash !== null || $delivery->posting_revision !== null
            || ! in_array($order->status, ['confirmed', 'partially_fulfilled'], true)
            || $order->confirmed_at === null || $order->confirmation_payload_hash === null || $order->cancelled_at !== null
            || $this->stock_movement_id !== null || $stored?->stock_movement_id !== null
            || ! $order->items()->whereKey($this->sales_order_item_id)->exists()
            || ($stored !== null && ($this->isDirty(['sales_order_id', 'sales_delivery_id', 'sales_order_item_id', 'uuid'])
                || $this->invoiceMatches()->exists() || $this->returnItems()->exists()))) {
            throw ValidationException::withMessages(['record' => __('Only unposted draft delivery lines can be changed. Identity and stock links are controlled by Sales workflows.')]);
        }
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw ValidationException::withMessages(['record' => __('Only draft deliveries can be changed.')]);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw ValidationException::withMessages(['record' => __('Only draft deliveries can be changed.')]);
    }

    /** @use HasFactory<SalesDeliveryItemFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'sales_order_id',
        'sales_delivery_id',
        'sales_order_item_id',
        'quantity',
        'stock_movement_id',
    ];

    protected static function newFactory(): SalesDeliveryItemFactory
    {
        return SalesDeliveryItemFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function salesDelivery(): BelongsTo
    {
        return $this->belongsTo(SalesDelivery::class, 'sales_delivery_id');
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function reservationConsumption(): HasOne
    {
        return $this->hasOne(SalesReservationConsumption::class);
    }

    public function invoiceMatches(): HasMany
    {
        return $this->hasMany(SalesInvoiceDeliveryMatch::class, 'sales_delivery_item_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_delivery_item_id');
    }
}
