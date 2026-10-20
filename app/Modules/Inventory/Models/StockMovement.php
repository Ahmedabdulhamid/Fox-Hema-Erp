<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryEvidenceBuilder;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Sales\Models\SalesDeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\ValidationException;

class StockMovement extends Model
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'SM';

    public function newEloquentBuilder($query): Builder
    {
        return new InventoryEvidenceBuilder($query);
    }

    private function guardSalesIssue(): void
    {
        if (! $this->exists && $this->reference_type === (new SalesDeliveryItem)->getMorphClass()
            && SalesDeliveryItem::whereKey($this->reference_id)->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted'))->exists()) {
            throw ValidationException::withMessages(['record' => __('Posted sale movements are immutable.')]);
        }
        if ($this->exists && self::whereKey($this->id)->where('reference_type', (new SalesDeliveryItem)->getMorphClass())->whereNotNull('posting_payload_hash')->exists()) {
            throw ValidationException::withMessages(['record' => __('Posted sale movements are immutable.')]);
        }
    }

    public function save(array $options = []): bool
    {
        $this->guardSalesIssue();

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        $this->guardSalesIssue();

        return parent::delete();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): mixed
    {
        $this->guardSalesIssue();

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): mixed
    {
        $this->guardSalesIssue();

        return parent::incrementOrDecrementEach($columns, $extra, $method);
    }

    use HasPublicUuid;

    protected static function booted(): void
    {
        $guard = function (StockMovement $movement): void {
            if ($movement->getOriginal('posting_payload_hash') !== null
                || $movement->newQuery()->whereKey($movement->id)->whereNotNull('posting_payload_hash')->exists()) {
                throw ValidationException::withMessages(['movement' => __('Posted stock movements are immutable. Post a compensating movement instead.')]);
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function reversesMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    protected $fillable = [
        'variant_id',
        'location_id',
        'type',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'valuation_unit_cost' => 'decimal:6',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }

    public function inventoryCostEntry(): HasOne
    {
        return $this->hasOne(InventoryCostEntry::class, 'stock_movement_id');
    }

    public function fifoLayer(): HasOne
    {
        return $this->hasOne(FifoLayer::class, 'stock_movement_id');
    }

    public function purchaseReceiptItem(): HasOne
    {
        return $this->hasOne(PurchaseReceiptItem::class, 'stock_movement_id');
    }

    public function salesDeliveryItem(): HasOne
    {
        return $this->hasOne(SalesDeliveryItem::class, 'stock_movement_id');
    }
}
