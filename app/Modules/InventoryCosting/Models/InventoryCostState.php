<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\InventoryCosting\Database\Factories\InventoryCostStateFactory;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCostState extends Model
{
    /** @use HasFactory<InventoryCostStateFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'variant_id',
        'location_id',
        'avg_cost',
        'on_hand_qty',
        'total_value',
    ];

    protected static function newFactory(): InventoryCostStateFactory
    {
        return InventoryCostStateFactory::new();
    }

    protected function casts(): array
    {
        return [
            'avg_cost' => 'decimal:6',
            'on_hand_qty' => 'decimal:4',
            'total_value' => 'decimal:4',
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
}
