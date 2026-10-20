<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\QuarantineReceipt;
use Brick\Math\BigDecimal;

class QuarantineStockService
{
    /** Current custody balance is derived from immutable receipts. No release or disposal events exist in Phase 5B. */
    public function balance(User $actor, int $variantId, int $locationId): array
    {
        app(InventoryAccess::class)->authorize($actor, InventoryPermissions::QUARANTINE_VIEW, $locationId);
        $receipts = QuarantineReceipt::where('variant_id', $variantId)->where('location_id', $locationId)->get();
        $quantity = $receipts->reduce(fn ($sum, $row) => $sum->plus($row->quantity), BigDecimal::zero());
        $value = $receipts->reduce(fn ($sum, $row) => $sum->plus($row->provisional_carrying_value), BigDecimal::zero());

        return ['quantity_held' => (string) $quantity->toScale(4),
            'provisional_carrying_value' => app(InventoryAccess::class)->allows($actor, InventoryPermissions::COST, $locationId) ? (string) $value->toScale(4) : null,
            'recoverable_value' => null, 'sellable_quantity' => '0.0000'];
    }
}
