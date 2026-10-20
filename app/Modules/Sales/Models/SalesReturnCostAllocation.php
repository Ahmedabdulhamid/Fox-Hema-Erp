<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesReturnCostAllocation extends ImmutableReturnEvidence
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'original_unit_cost' => 'decimal:6', 'original_cost' => 'decimal:4',
            'carrying_value' => 'decimal:4', 'return_variance' => 'decimal:4', 'loss_value' => 'decimal:4', 'posted_at' => 'datetime'];
    }

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesReturnItem(): BelongsTo
    {
        return $this->belongsTo(SalesReturnItem::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function quarantineReceipt(): HasOne
    {
        return $this->hasOne(QuarantineReceipt::class, 'return_cost_allocation_id');
    }

    public function scrapReceipt(): HasOne
    {
        return $this->hasOne(SalesReturnScrapReceipt::class, 'return_cost_allocation_id');
    }
}
