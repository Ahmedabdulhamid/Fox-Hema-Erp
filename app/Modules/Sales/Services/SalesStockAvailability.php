<?php

namespace App\Modules\Sales\Services;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class SalesStockAvailability
{
    /**
     * Lines have already passed the authoritative four-decimal pricing validation.
     * Locks last through the caller's draft transaction; this never reserves stock.
     *
     * @param  array<array-key, array{variant_id: int, quantity: string}>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     */
    public function validate(User $actor, Location $branch, array $lines, Collection $variants): Collection
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Sales stock validation requires the draft transaction.');
        }
        app(SalesAccess::class)->authorize($actor, SalesPermissions::VIEW, (int) $branch->id);
        $requested = [];
        foreach ($lines as $line) {
            $id = $line['variant_id'];
            $requested[$id] = ($requested[$id] ?? BigDecimal::zero())->plus($line['quantity']);
        }
        ksort($requested, SORT_NUMERIC);
        $balances = $this->lockBalances(array_keys($requested), (int) $branch->id);
        $available = [];
        // Match inventory posting's (variant_id, location_id) order with exact unique-key reads.
        foreach ($requested as $id => $quantity) {
            $balance = $balances->get($id);
            $available[$id] = BigDecimal::of($balance?->quantity_available ?? '0.0000');
        }
        $errors = [];
        foreach ($lines as $index => $line) {
            $id = $line['variant_id'];
            if (! $available[$id]->isPositive() || $requested[$id]->isGreaterThan($available[$id])) {
                $errors['items.'.$index.'.quantity'] = __('Requested quantity for :variant is :requested, but available stock at :branch is :available. Reduce the quantity or choose another variant.', [
                    'variant' => $variants->get($id)->displayName().' ('.$variants->get($id)->sku.')',
                    'requested' => QuantityFormatter::format((string) $requested[$id]->toScale(4)),
                    'branch' => $branch->name,
                    'available' => QuantityFormatter::format((string) $available[$id]->toScale(4)),
                ]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $balances;
    }

    /** @param list<int> $variantIds */
    public function lockBalances(array $variantIds, int $locationId): Collection
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Sales stock locking requires the document transaction.');
        }
        $variantIds = array_values(array_unique($variantIds));
        sort($variantIds, SORT_NUMERIC);
        $balances = collect();
        foreach ($variantIds as $id) {
            $balance = StockBalance::where('variant_id', $id)->where('location_id', $locationId)->lockForUpdate()->first();
            if ($balance !== null) {
                $balances->put($id, $balance);
            }
        }

        return $balances;
    }
}
