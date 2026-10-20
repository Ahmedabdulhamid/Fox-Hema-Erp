<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesFulfillmentService
{
    /** @return array<int, array{ordered: string, delivered: string, remaining: string}> */
    public function quantities(SalesOrder $order, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new \LogicException('Fulfillment locks require the Sales document transaction.');
        }
        $items = $order->items()->orderBy('id');
        $items = ($lock ? $items->lockForUpdate() : $items)->get();
        $query = $order->deliveryItems()->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted'))->orderBy('id');
        $posted = ($lock ? $query->lockForUpdate() : $query)->get();
        $delivered = [];
        foreach ($posted as $item) {
            $delivered[$item->sales_order_item_id] = ($delivered[$item->sales_order_item_id] ?? BigDecimal::zero())->plus($item->quantity);
        }
        $result = [];
        foreach ($items as $item) {
            $quantity = $delivered[$item->id] ?? BigDecimal::zero();
            $remaining = BigDecimal::of($item->quantity)->minus($quantity);
            if ($quantity->isNegative() || $remaining->isNegative()) {
                throw ValidationException::withMessages(['record' => __('Posted delivery quantities require reconciliation.')]);
            }
            $result[$item->id] = ['ordered' => $item->quantity, 'delivered' => (string) $quantity->toScale(4), 'remaining' => (string) $remaining->toScale(4)];
        }

        return $result;
    }
}
