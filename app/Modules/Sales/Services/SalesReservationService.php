<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReservationConsumption;
use App\Modules\Sales\Models\SalesStockReservation;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class SalesReservationService
{
    public function __construct(private readonly SalesStockAvailability $stock, private readonly PurchaseOrderCalculator $calculator) {}

    /** Internal transaction primitive: the confirmation service owns the order lock and commit. */
    public function reserve(SalesOrder $order, array $lines): void
    {
        $this->assertTransaction();
        if ($order->reservations()->exists()) {
            $this->invalid();
        }
        $quantities = $this->quantities($lines);
        $balances = $this->stock->lockBalances(array_keys($quantities), (int) $order->location_id);
        foreach ($quantities as $variantId => $quantity) {
            $balance = $balances->get($variantId);
            $this->assertBalance($balance);
            if ($quantity->isGreaterThan($balance->quantity_available)) {
                $this->invalid();
            }
            // Model writes are blocked: only this locked, invariant-checked workflow changes ownership.
            DB::table('sales_stock_reservations')->insert([
                'uuid' => (string) Str::uuid7(), 'sales_order_id' => $order->id,
                'variant_id' => $variantId, 'location_id' => $order->location_id,
                'quantity_reserved' => (string) $quantity->toScale(4),
                'quantity_consumed' => '0.0000', 'quantity_released' => '0.0000',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $reserved = BigDecimal::of($balance->quantity_reserved)->plus($quantity);
            $balance->fill(['quantity_reserved' => (string) $reserved->toScale(4),
                'quantity_available' => (string) BigDecimal::of($balance->quantity_on_hand)->minus($reserved)->toScale(4)])->save();
        }
    }

    public function release(SalesOrder $order): void
    {
        [$reservations, $balances] = $this->verify($order);
        foreach ($reservations as $reservation) {
            $active = BigDecimal::of($reservation->activeQuantity());
            $balance = $balances->get($reservation->variant_id);
            $reserved = BigDecimal::of($balance->quantity_reserved)->minus($active);
            DB::table('sales_stock_reservations')->where('id', $reservation->id)->update([
                'quantity_released' => (string) BigDecimal::of($reservation->quantity_released)->plus($active)->toScale(4),
                'updated_at' => now(),
            ]);
            $balance->fill(['quantity_reserved' => (string) $reserved->toScale(4),
                'quantity_available' => (string) BigDecimal::of($balance->quantity_on_hand)->minus($reserved)->toScale(4)])->save();
        }
    }

    /** Replays verify complete ownership rather than treating a status flag as proof of success. */
    public function verify(SalesOrder $order, bool $released = false): array
    {
        $this->assertTransaction();
        $quantities = $this->quantities($order->items()->orderBy('id')->get()->toArray());
        $balances = $this->stock->lockBalances(array_keys($quantities), (int) $order->location_id);
        $reservations = $order->reservations()->orderBy('variant_id')->lockForUpdate()->get();
        if ($reservations->count() !== count($quantities)) {
            $this->invalid();
        }
        foreach ($reservations as $reservation) {
            $expected = $quantities[$reservation->variant_id] ?? null;
            if ($expected === null || (int) $reservation->location_id !== (int) $order->location_id
                || ! $expected->isEqualTo($reservation->quantity_reserved)
                || ! BigDecimal::of($reservation->quantity_consumed)->isZero()
                || ! BigDecimal::of($reservation->quantity_released)->isEqualTo($released ? $expected : '0')) {
                $this->invalid();
            }
            $this->assertBalance($balances->get($reservation->variant_id));
        }

        return [$reservations, $balances];
    }

    /** Fulfillment verifier; Phase 2's zero-consumption verifier keeps its original contract. */
    public function verifyFulfillment(SalesOrder $order): array
    {
        $this->assertTransaction();
        $expected = $this->quantities($order->items()->orderBy('id')->lockForUpdate()->get()->toArray());
        $balances = $this->stock->lockBalances(array_keys($expected), (int) $order->location_id);
        $reservations = $order->reservations()->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');
        if ($reservations->count() !== count($expected)) {
            $this->invalid();
        }
        $events = SalesReservationConsumption::where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy('sales_delivery_item_id');
        $posted = $order->deliveryItems()->with(['salesOrderItem', 'salesDelivery', 'stockMovement.inventoryCostEntry'])
            ->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted'))->orderBy('id')->lockForUpdate()->get();
        if ($events->count() !== $posted->count()) {
            $this->invalid();
        }
        $consumed = [];
        foreach ($posted as $item) {
            $event = $events->get($item->id);
            $variantId = $item->salesOrderItem->variant_id;
            $reservation = $reservations->get($variantId);
            if ($event === null || $reservation === null || (int) $event->sales_stock_reservation_id !== (int) $reservation->id
                || (int) $event->sales_order_item_id !== (int) $item->sales_order_item_id
                || (int) $event->variant_id !== (int) $variantId || (int) $event->location_id !== (int) $order->location_id
                || $event->quantity !== $item->quantity || $item->salesDelivery->posted_at === null
                || $item->stockMovement?->inventoryCostEntry?->entry_type !== 'issue'
                || ! BigDecimal::of($item->quantity)->negated()->isEqualTo($item->stockMovement->quantity)) {
                $this->invalid();
            }
            $consumed[$variantId] = ($consumed[$variantId] ?? BigDecimal::zero())->plus($event->quantity);
        }
        foreach ($expected as $variantId => $quantity) {
            $reservation = $reservations->get($variantId);
            if ((int) $reservation->location_id !== (int) $order->location_id
                || ! $quantity->isEqualTo($reservation->quantity_reserved)
                || ! ($consumed[$variantId] ?? BigDecimal::zero())->isEqualTo($reservation->quantity_consumed)
                || ! BigDecimal::of($reservation->quantity_released)->isZero()
                || BigDecimal::of($reservation->activeQuantity())->isNegative()) {
                $this->invalid();
            }
            $this->assertBalance($balances->get($variantId));
        }

        return [$reservations, $balances];
    }

    /** @param list<SalesDeliveryItem> $items */
    public function consume(SalesOrder $order, array $items, User $actor, CarbonInterface $postedAt): void
    {
        [$reservations, $balances] = $this->verifyFulfillment($order);
        foreach ($items as $item) {
            $variantId = $item->salesOrderItem->variant_id;
            $reservation = $reservations->get($variantId);
            $quantity = BigDecimal::of($item->quantity);
            $balance = $balances->get($variantId);
            if ((int) $item->sales_order_id !== (int) $order->id || $reservation === null || ! $quantity->isPositive()
                || $quantity->isGreaterThan($reservation->activeQuantity()) || $quantity->isGreaterThan($balance->quantity_reserved)
                || $item->reservationConsumption()->exists()) {
                $this->invalid();
            }
            DB::table('sales_reservation_consumptions')->insert([
                'uuid' => (string) Str::uuid7(), 'sales_stock_reservation_id' => $reservation->id,
                'sales_delivery_item_id' => $item->id, 'sales_order_item_id' => $item->sales_order_item_id,
                'sales_order_id' => $order->id, 'variant_id' => $variantId, 'location_id' => $order->location_id,
                'quantity' => $item->quantity, 'consumed_by' => $actor->id, 'consumed_at' => $postedAt,
                'created_at' => $postedAt, 'updated_at' => $postedAt]);
            $reservation->quantity_consumed = (string) BigDecimal::of($reservation->quantity_consumed)->plus($quantity)->toScale(4);
            DB::table('sales_stock_reservations')->where('id', $reservation->id)->update([
                'quantity_consumed' => $reservation->quantity_consumed, 'updated_at' => $postedAt]);
            $reserved = BigDecimal::of($balance->quantity_reserved)->minus($quantity);
            $balance->fill(['quantity_reserved' => (string) $reserved->toScale(4),
                'quantity_available' => (string) BigDecimal::of($balance->quantity_on_hand)->minus($reserved)->toScale(4)])->save();
        }
    }

    private function quantities(array $lines): array
    {
        if ($lines === []) {
            $this->invalid();
        }
        $quantities = [];
        foreach ($lines as $line) {
            $quantity = BigDecimal::of($this->calculator->decimal($line['quantity'] ?? null, 'items', 18, 4));
            if (! $quantity->isPositive()) {
                $this->invalid();
            }
            $id = (int) $line['variant_id'];
            $quantities[$id] = ($quantities[$id] ?? BigDecimal::zero())->plus($quantity);
        }
        ksort($quantities, SORT_NUMERIC);

        return $quantities;
    }

    private function assertBalance(?StockBalance $balance): void
    {
        if ($balance === null) {
            $this->invalid();
        }
        $onHand = BigDecimal::of($balance->quantity_on_hand);
        $reserved = BigDecimal::of($balance->quantity_reserved);
        if ($onHand->isNegative() || $reserved->isNegative() || $reserved->isGreaterThan($onHand)
            || ! $onHand->minus($reserved)->isEqualTo($balance->quantity_available)) {
            $this->invalid();
        }
        $known = BigDecimal::zero();
        // Current reads under the balance lock, including other orders, avoid stale MVCC snapshots.
        $owners = SalesStockReservation::where('variant_id', $balance->variant_id)->where('location_id', $balance->location_id)
            ->whereRaw('quantity_reserved > quantity_consumed + quantity_released')->orderBy('id')->lockForUpdate()->get();
        foreach ($owners as $owner) {
            $known = $known->plus($owner->activeQuantity());
        }
        // Preserve pre-existing unowned holds; never release them on another order's behalf.
        if ($known->isGreaterThan($reserved)) {
            $this->invalid();
        }
    }

    private function assertTransaction(): void
    {
        if (DB::connection()->transactionLevel() === 0) {
            throw new LogicException('Reservations require the Sales document transaction.');
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['record' => __('Stock balances or reservation ownership are inconsistent. Review the records before continuing.')]);
    }
}
