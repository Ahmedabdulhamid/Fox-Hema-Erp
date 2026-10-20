<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnQuantityService
{
    /** Caller holds the source order lock. Locking reads avoid stale MVCC snapshots. */
    public function quantities(array $deliveryItemIds, ?int $excludeReturnId = null, bool $lock = true): array
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new \LogicException('Return quantity claims require the document transaction.');
        }
        $query = SalesDeliveryItem::whereKey($deliveryItemIds)->orderBy('id');
        $deliveries = ($lock ? $query->lockForUpdate() : $query)->get();
        $query = DB::table('sales_return_items as i')->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->whereIn('i.sales_delivery_item_id', $deliveryItemIds)->orderBy('i.id')
            ->select(['i.*', 'r.status as return_status', 'r.approved_by', 'r.approved_at', 'r.approval_payload_hash']);
        $items = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($lock) {
            foreach ($items->where('return_status', 'approved')->pluck('sales_return_id')->unique() as $returnId) {
                app(SalesReturnApprovalService::class)->verifyApproved(SalesReturn::findOrFail($returnId));
            }
        }
        $query = DB::table('sales_return_quantity_claims')->whereIn('sales_delivery_item_id', $deliveryItemIds)->orderBy('id');
        $claims = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('sales_return_item_id');
        $result = [];
        foreach ($deliveries as $delivery) {
            $result[$delivery->id] = ['delivered' => $delivery->quantity, 'claimed' => '0.0000', 'posted' => '0.0000', 'remaining' => $delivery->quantity];
        }
        foreach ($items as $item) {
            $claim = $claims->get($item->id);
            if ($item->return_status === 'approved') {
                if ($claim === null || $claim->status !== 'active' || $claim->quantity !== $item->quantity
                    || (int) $claim->sales_return_id !== (int) $item->sales_return_id
                    || (int) $claim->approved_by !== (int) $item->approved_by || $claim->approved_at !== $item->approved_at
                    || $item->approval_payload_hash === null || $claim->approval_identity !== $item->approval_payload_hash) {
                    $this->invalid();
                }
            } elseif ($claim?->status === 'active') {
                $this->invalid();
            }
            if ($excludeReturnId !== null && (int) $item->sales_return_id === $excludeReturnId) {
                continue;
            }
            $field = match ($item->return_status) {
                'approved' => 'claimed',
                'posted' => 'posted',
                default => null,
            };
            if ($field !== null) {
                $id = $item->sales_delivery_item_id;
                $result[$id][$field] = (string) BigDecimal::of($result[$id][$field])->plus($item->quantity)->toScale(4);
            }
        }
        foreach ($result as &$row) {
            $remaining = BigDecimal::of($row['delivered'])->minus($row['claimed'])->minus($row['posted']);
            if ($remaining->isNegative()) {
                $this->invalid();
            }
            $row['remaining'] = (string) $remaining->toScale(4);
        }

        return $result;
    }

    public function assertAvailable(array $lines, ?int $excludeReturnId = null): void
    {
        $requested = [];
        foreach ($lines as $line) {
            $id = $line['sales_delivery_item_id'];
            $quantity = BigDecimal::of($line['quantity']);
            if (! $quantity->isPositive()) {
                $this->invalid();
            }
            $requested[$id] = ($requested[$id] ?? BigDecimal::zero())->plus($quantity);
        }
        $summary = $this->quantities(array_keys($requested), $excludeReturnId);
        foreach ($requested as $id => $quantity) {
            if (! isset($summary[$id]) || $quantity->isGreaterThan($summary[$id]['remaining'])) {
                throw ValidationException::withMessages(['record' => __('returns.over_return')]);
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.quantity_evidence_invalid')]);
    }
}
