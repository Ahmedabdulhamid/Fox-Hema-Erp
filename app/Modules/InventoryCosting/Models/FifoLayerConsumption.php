<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\InventoryCosting\Database\Factories\FifoLayerConsumptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class FifoLayerConsumption extends Model
{
    public function newEloquentBuilder($query): Builder
    {
        return new InventoryEvidenceBuilder($query);
    }

    private function guardIssue(): void
    {
        if (! $this->exists && InventoryCostEntry::whereKey($this->cost_entry_id)->where('entry_type', 'issue')
            ->whereHas('stockMovement.salesDeliveryItem.salesDelivery', fn ($query) => $query->where('status', 'posted'))->exists()) {
            throw ValidationException::withMessages(['record' => __('Posted FIFO issue consumptions are immutable.')]);
        }
        if ($this->exists) {
            $stored = self::findOrFail($this->id);
            if (InventoryCostEntry::whereIn('id', [$stored->cost_entry_id, $this->cost_entry_id])->where('entry_type', 'issue')->exists()) {
                throw ValidationException::withMessages(['record' => __('Posted FIFO issue consumptions are immutable.')]);
            }
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

    /** @use HasFactory<FifoLayerConsumptionFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'cost_entry_id',
        'fifo_layer_id',
        'quantity',
        'unit_cost',
        'total_cost',
    ];

    protected static function newFactory(): FifoLayerConsumptionFactory
    {
        return FifoLayerConsumptionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:4',
        ];
    }

    public function costEntry(): BelongsTo
    {
        return $this->belongsTo(InventoryCostEntry::class, 'cost_entry_id');
    }

    public function fifoLayer(): BelongsTo
    {
        return $this->belongsTo(FifoLayer::class, 'fifo_layer_id');
    }
}
