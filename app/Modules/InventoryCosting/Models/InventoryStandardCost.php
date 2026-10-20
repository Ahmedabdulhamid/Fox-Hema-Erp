<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\InventoryCosting\Database\Factories\InventoryStandardCostFactory;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStandardCost extends Model
{
    /** @use HasFactory<InventoryStandardCostFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'variant_id',
        'standard_cost',
        'updated_by',
    ];

    protected static function newFactory(): InventoryStandardCostFactory
    {
        return InventoryStandardCostFactory::new();
    }

    protected function casts(): array
    {
        return [
            'standard_cost' => 'decimal:6',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InventoryStandardCostRevision::class, 'standard_cost_id');
    }
}
