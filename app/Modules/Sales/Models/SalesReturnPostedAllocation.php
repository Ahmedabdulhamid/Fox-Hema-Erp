<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnPostedAllocation extends ImmutableReturnEvidence
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
