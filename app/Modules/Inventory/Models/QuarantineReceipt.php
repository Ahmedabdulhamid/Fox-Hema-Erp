<?php

namespace App\Modules\Inventory\Models;

use App\Models\Concerns\HasDocumentNumber;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Sales\Models\ImmutableReturnEvidence;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuarantineReceipt extends ImmutableReturnEvidence
{
    use HasDocumentNumber;

    public const DOCUMENT_TYPE = 'QC';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'original_cost' => 'decimal:4', 'provisional_carrying_value' => 'decimal:4', 'received_at' => 'datetime'];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SalesReturnCostAllocation::class, 'return_cost_allocation_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QuarantineInspection::class);
    }
}
