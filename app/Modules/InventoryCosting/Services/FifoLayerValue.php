<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\InventoryCosting\Models\FifoLayer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FifoLayerValue
{
    public function at(FifoLayer $layer, BigDecimal $quantity): BigDecimal
    {
        if ($layer->initial_value === null) {
            return $quantity->multipliedBy($layer->unit_cost)->toScale(4, RoundingMode::HalfUp);
        }

        return BigDecimal::of($layer->initial_value)->multipliedBy($quantity)->dividedBy($layer->quantity, 4, RoundingMode::HalfUp);
    }

    public function remaining(FifoLayer $layer): BigDecimal
    {
        $value = $this->at($layer, BigDecimal::of($layer->remaining_qty));
        if ($layer->initial_value !== null && ($layer->remaining_value === null || $layer->available_at === null
            || ! $value->isEqualTo($layer->remaining_value))) {
            throw ValidationException::withMessages(['valuation' => __('returns.fifo_value_invalid')]);
        }

        return $value;
    }

    public function consume(FifoLayer $layer, BigDecimal $quantity): BigDecimal
    {
        $opening = $this->remaining($layer);
        $next = BigDecimal::of($layer->remaining_qty)->minus($quantity);
        if ($next->isNegative()) {
            throw ValidationException::withMessages(['valuation' => __('returns.fifo_value_invalid')]);
        }
        $closing = $this->at($layer, $next);
        $layer->remaining_qty = (string) $next->toScale(4);
        if ($layer->initial_value !== null) {
            $layer->remaining_value = (string) $closing->toScale(4);
        }

        return $opening->minus($closing);
    }

    /** Exact layers are immutable through ordinary ORM writes; only this valuation primitive updates residuals. */
    public function persist(FifoLayer $layer): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('FIFO residuals require the valuation transaction.');
        }
        if ($layer->initial_value === null) {
            $layer->save();
        } else {
            $this->remaining($layer);
            DB::table('fifo_layers')->where('id', $layer->id)->update([
                'remaining_qty' => $layer->remaining_qty, 'remaining_value' => $layer->remaining_value, 'updated_at' => now(),
            ]);
            $layer->syncOriginal();
        }
    }
}
