<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Services\EstimatedCostResolver;
use App\Modules\InventoryCosting\Services\ProfitabilityCalculator;
use App\Modules\Sales\Models\SalesOrder;
use Brick\Math\BigDecimal;

class SalesProfitability
{
    public function canView(?User $actor, ?int $locationId = null): bool
    {
        return app(ModuleAccess::class)->isActive('inventory_costing')
            && app(SalesAccess::class)->allows($actor, SalesPermissions::VIEW, $locationId)
            && app(InventoryAccess::class)->allows($actor, InventoryPermissions::COST, $locationId);
    }

    /** @param array<int|string, array<string, mixed>> $lines */
    public function estimates(array $lines, int $locationId): array
    {
        $costs = app(EstimatedCostResolver::class)->lines($lines, $locationId);
        $result = [];
        foreach ($lines as $key => $line) {
            $revenue = (string) BigDecimal::of($line['subtotal'])->minus($line['discount_amount']);
            $result[$key] = app(ProfitabilityCalculator::class)->calculate($revenue, $costs[$key]['cost']) + $costs[$key];
        }

        return $result;
    }

    /** Confirmation owns the transaction and locks; commercial pricing is unchanged. */
    public function capture(SalesOrder $order): void
    {
        $items = $order->items()->orderBy('id')->get();
        $estimates = $this->estimates($items->map->toArray()->all(), (int) $order->location_id);
        foreach ($items as $key => $item) {
            $item->profitability_snapshot = array_intersect_key($estimates[$key], array_flip(['revenue', 'cost', 'source', 'method', 'state_id', 'revision_id']))
                + ['version' => 1, 'currency' => 'EGP', 'quantity' => $item->quantity, 'captured_at' => now()->toIso8601String()];
            $item->save();
        }
    }

    public function order(User $actor, SalesOrder $order): array
    {
        $order = SalesOrder::findOrFail($order->id);
        abort_unless($this->canView($actor, (int) $order->location_id), 403);
        $items = $order->items()->orderBy('id')->get();
        if ($order->confirmed_at === null && $order->status === 'draft') {
            $lines = $this->estimates($items->map->toArray()->all(), (int) $order->location_id);
        } else {
            $lines = $items->map(function ($item): array {
                $snapshot = $item->profitability_snapshot;

                return app(ProfitabilityCalculator::class)->calculate(
                    (string) BigDecimal::of($item->subtotal)->minus($item->discount_amount), $snapshot['cost'] ?? null,
                ) + ['source' => $snapshot['source'] ?? 'historical-estimate-unavailable'];
            })->all();
        }

        return ['lines' => array_combine($items->modelKeys(), array_values($lines)), 'summary' => $this->summarize($lines)];
    }

    /** @param array<int|string, array<string, mixed>> $lines */
    public function summarize(array $lines): array
    {
        $revenue = BigDecimal::zero();
        $cost = BigDecimal::zero();
        $available = $lines !== [];
        foreach ($lines as $line) {
            $revenue = $revenue->plus($line['revenue']);
            $available = $available && $line['cost'] !== null;
            $cost = $cost->plus($line['cost'] ?? '0');
        }

        return app(ProfitabilityCalculator::class)->calculate((string) $revenue, $available ? (string) $cost : null);
    }
}
