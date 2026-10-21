<?php

namespace App\Modules\InventoryCosting\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class EstimatedCostResolver
{
    /**
     * Read-only issue simulation; repeated variants share a simulated pool.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array<int|string, array{cost: ?string, source: string, method: ?string, state_id: ?int, revision_id: ?int}>
     */
    public function lines(array $lines, int $locationId): array
    {
        $ids = array_column($lines, 'variant_id');
        $variants = ProductVariant::with('product')->whereKey($ids)->get()->keyBy('id');
        $methods = InventoryValuationSetting::whereIn('category_id', $variants->pluck('product.category_id'))->pluck('costing_method', 'category_id');
        $states = InventoryCostState::whereIn('variant_id', $ids)->where('location_id', $locationId)->get()->keyBy('variant_id');
        $legacy = InventoryCostState::whereIn('variant_id', $ids)->whereNull('location_id')->pluck('variant_id')->all();
        $balances = StockBalance::whereIn('variant_id', $ids)->where('location_id', $locationId)->get()->keyBy('variant_id');
        $latest = InventoryCostEntry::join('stock_movements as m', 'm.id', '=', 'inventory_cost_entries.stock_movement_id')
            ->whereIn('m.variant_id', $ids)->where('m.location_id', $locationId)
            ->selectRaw('m.variant_id, MAX(inventory_cost_entries.id) as latest_id')->groupBy('m.variant_id');
        $entries = InventoryCostEntry::with('stockMovement')->whereIn('id', $latest->pluck('latest_id'))->get()->keyBy('stockMovement.variant_id');
        $layers = FifoLayer::whereIn('variant_id', $ids)->where('remaining_qty', '>', '0')->whereHas('stockMovement', fn ($q) => $q->where('location_id', $locationId))
            ->orderByRaw('COALESCE(available_at, received_at)')->orderBy('id')->get()->groupBy('variant_id');
        $revisions = InventoryStandardCostRevision::join('inventory_standard_costs as s', 's.id', '=', 'inventory_standard_cost_revisions.standard_cost_id')
            ->whereIn('s.variant_id', $ids)->where('effective_at', '<=', now())->orderByDesc('effective_at')->orderByDesc('inventory_standard_cost_revisions.id')
            ->get(['inventory_standard_cost_revisions.*', 's.variant_id'])->groupBy('variant_id');
        $result = [];
        $invalidVariants = [];
        foreach ($lines as $key => $line) {
            $id = (int) $line['variant_id'];
            $variant = $variants->get($id);
            $method = $methods[$variant?->product?->category_id ?? 0] ?? null;
            $state = $states->get($id);
            $balance = $balances->get($id);
            $revision = $revisions->get($id)?->first();
            $result[$key] = ['cost' => null, 'source' => 'unavailable', 'method' => $method, 'state_id' => $state?->id, 'revision_id' => $revision?->id];
            $quantity = BigDecimal::of($line['quantity']);
            if (isset($invalidVariants[$id]) || in_array($id, $legacy, true) || $state === null || $balance === null
                || ! in_array($method, ['avco', 'fifo', 'standard'], true) || $entries->get($id)?->method !== $method
                || ! BigDecimal::of($state->on_hand_qty)->isEqualTo($balance->quantity_on_hand)
                || ! $quantity->isPositive() || $quantity->isGreaterThan($state->on_hand_qty)
                || BigDecimal::of($state->total_value)->isNegative() || BigDecimal::of($state->avg_cost)->isNegative()) {
                $invalidVariants[$id] = true;

                continue;
            }
            $opening = BigDecimal::of($state->on_hand_qty);
            $closing = $opening->minus($quantity);
            $value = BigDecimal::of($state->total_value);
            if ($method === 'fifo') {
                $variantLayers = $layers->get($id, collect());
                $layerQuantity = BigDecimal::zero();
                $layerValue = BigDecimal::zero();
                try {
                    foreach ($variantLayers as $layer) {
                        $layerQuantity = $layerQuantity->plus($layer->remaining_qty);
                        $layerValue = $layerValue->plus(app(FifoLayerValue::class)->remaining($layer));
                    }
                    if (! $layerQuantity->isEqualTo($opening) || ! $layerValue->isEqualTo($value)) {
                        $invalidVariants[$id] = true;

                        continue;
                    }
                    $cost = BigDecimal::zero();
                    $remaining = $quantity;
                    foreach ($variantLayers as $layer) {
                        if ($remaining->isZero()) {
                            break;
                        }
                        $used = BigDecimal::min($remaining, BigDecimal::of($layer->remaining_qty));
                        $cost = $cost->plus(app(FifoLayerValue::class)->consume($layer, $used));
                        $remaining = $remaining->minus($used);
                    }
                } catch (ValidationException) {
                    $invalidVariants[$id] = true;

                    continue;
                }
            } elseif ($method === 'standard') {
                if ($revision === null || $revisions[$id]->where('effective_at', $revision->effective_at)->pluck('new_cost')->unique()->count() !== 1
                    || ! $opening->multipliedBy($revision->new_cost)->toScale(4, RoundingMode::HalfUp)->isEqualTo($value)) {
                    $invalidVariants[$id] = true;

                    continue;
                }
                $cost = $value->minus($closing->multipliedBy($revision->new_cost)->toScale(4, RoundingMode::HalfUp));
            } else {
                $cost = $closing->isZero() ? $value : $value->multipliedBy($quantity)->dividedBy($opening, 4, RoundingMode::HalfUp);
            }
            $result[$key]['cost'] = (string) $cost->toScale(4);
            $result[$key]['source'] = 'location-'.$method;
            $state->on_hand_qty = (string) $closing;
            $state->total_value = (string) $value->minus($cost);
            $balance->quantity_on_hand = (string) $closing;
        }

        return $result;
    }
}
