<?php

namespace App\Modules\Sales\Models;

class SalesReturnScrapReceipt extends ImmutableReturnEvidence
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'original_cost' => 'decimal:4', 'operational_loss_value' => 'decimal:4', 'accepted_at' => 'datetime'];
    }
}
