<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Database\Factories\FifoLayerFactory;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class FifoLayer extends Model
{
    public function newEloquentBuilder($query): Builder
    {
        return new InventoryEvidenceBuilder($query);
    }

    public function save(array $options = []): bool
    {
        if ($this->initial_value !== null || ($this->exists && self::whereKey($this->id)->whereNotNull('initial_value')->exists())) {
            throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        if ($this->initial_value !== null || ($this->exists && self::whereKey($this->id)->whereNotNull('initial_value')->exists())) {
            throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
        }

        return parent::delete();
    }

    /** @use HasFactory<FifoLayerFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'variant_id',
        'stock_movement_id',
        'quantity',
        'unit_cost',
        'remaining_qty',
        'received_at',
        'source_layer_id',
    ];

    protected static function newFactory(): FifoLayerFactory
    {
        return FifoLayerFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'remaining_qty' => 'decimal:4',
            'received_at' => 'datetime',
            'initial_value' => 'decimal:4',
            'remaining_value' => 'decimal:4',
            'available_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function sourceLayer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_layer_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(FifoLayerConsumption::class, 'fifo_layer_id');
    }
}
