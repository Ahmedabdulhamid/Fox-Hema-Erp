<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'variant_id',
        'location_id',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_available',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
            'quantity_available' => 'decimal:4',
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
