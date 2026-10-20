<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Database\Factories\InventoryCostEntryFactory;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class InventoryCostEntry extends Model
{
    public function newEloquentBuilder($query): Builder
    {
        return new InventoryEvidenceBuilder($query);
    }

    private function guardIssue(): void
    {
        if ($this->stockMovement?->reference_type === (new SalesReturnCostAllocation)->getMorphClass()
            || ($this->exists && self::whereKey($this->id)->whereHas('stockMovement', fn ($q) => $q->where('reference_type',
                (new SalesReturnCostAllocation)->getMorphClass()))->exists())) {
            throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
        }
        if ($this->exists && ($this->getOriginal('entry_type') === 'issue'
            || self::whereKey($this->id)->where('entry_type', 'issue')->exists())) {
            throw ValidationException::withMessages(['record' => __('Posted issue valuations are immutable.')]);
        }
    }

    public function save(array $options = []): bool
    {
        $this->guardIssue();

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        $this->guardIssue();

        return parent::delete();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): mixed
    {
        $this->guardIssue();

        return parent::incrementOrDecrement($column, $amount, $extra, $method);
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): mixed
    {
        $this->guardIssue();

        return parent::incrementOrDecrementEach($columns, $extra, $method);
    }

    /** @use HasFactory<InventoryCostEntryFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'stock_movement_id',
        'method',
        'quantity',
        'unit_cost',
        'total_cost',
        'entry_type',
        'purchase_unit_cost',
        'purchase_value',
        'purchase_price_variance',
        'standard_cost_revision_id',
    ];

    protected static function newFactory(): InventoryCostEntryFactory
    {
        return InventoryCostEntryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:4',
            'purchase_unit_cost' => 'decimal:6',
            'purchase_value' => 'decimal:4',
            'purchase_price_variance' => 'decimal:4',
        ];
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function standardCostRevision(): BelongsTo
    {
        return $this->belongsTo(InventoryStandardCostRevision::class, 'standard_cost_revision_id');
    }

    protected static function booted(): void
    {
        $guard = function (self $entry): void {
            if ($entry->getOriginal('purchase_value') !== null
                || self::whereKey($entry->id)->whereNotNull('purchase_value')->exists()
                || $entry->stockMovement?->reference_type === SizeAllocationItem::class) {
                throw ValidationException::withMessages(['valuation' => __('Posted receipt valuations are immutable.')]);
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function fifoLayerConsumptions(): HasMany
    {
        return $this->hasMany(FifoLayerConsumption::class, 'cost_entry_id');
    }
}
