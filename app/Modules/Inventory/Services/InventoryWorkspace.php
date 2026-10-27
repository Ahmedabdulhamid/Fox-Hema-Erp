<?php

namespace App\Modules\Inventory\Services;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

class InventoryWorkspace
{
    /** @param array<string, array<string, mixed>> $filters */
    public function catalog(Builder $query, array $filters, bool $allocations = false): Builder
    {
        $value = fn (string $key): mixed => $filters[$key]['value'] ?? null;
        $query->when(filled($value('location_id')), fn (Builder $q) => $q->where($q->qualifyColumn('location_id'), $value('location_id')));
        $product = $value('product') ?? $value('product_id');
        $relation = $allocations ? 'product' : 'variant.product';
        $query->when(filled($value('brand')), fn (Builder $q) => $q->whereHas($relation, fn (Builder $p) => $value('brand') === 'none' ? $p->whereNull('brand_id') : $p->where('brand_id', $value('brand'))));
        $query->when(filled($product), fn (Builder $q) => $allocations ? $q->where('product_id', $product) : $q->whereHas('variant', fn (Builder $v) => $v->where('product_id', $product)));
        $variantRelation = $allocations ? 'items.variant' : 'variant';
        $query->when(filled($value('variant')), fn (Builder $q) => $q->whereHas($variantRelation, fn (Builder $v) => $v->whereKey($value('variant'))));
        $query->when(filled($value('color')), fn (Builder $q) => $q->whereHas($variantRelation, fn (Builder $v) => $value('color') === 'none' ? $v->whereNull('color_id') : $v->where('color_id', $value('color'))));

        return $query;
    }

    public function records(?User $actor, string $permission, string $kind): Builder
    {
        $model = match ($kind) {
            'movements' => StockMovement::class,
            'quarantine' => QuarantineReceipt::class,
            default => StockBalance::class,
        };
        $query = app(InventoryAccess::class)->scope($model::query(), $actor, $permission);
        if ($kind === 'allocations') {
            $query = app(InventoryAccess::class)->scope($query, $actor, InventoryPermissions::BALANCES);
        }

        return $query;
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @return array<int|string, string>
     */
    public function options(?User $actor, string $permission, string $kind, string $field, array $filters, string $search = '', mixed $selected = null): array
    {
        $parents = match ($field) {
            'brand' => ['location_id'],
            'product', 'product_id' => ['location_id', 'brand'],
            'color' => ['location_id', 'brand', 'product', 'product_id'],
            default => ['location_id', 'brand', 'product', 'product_id', 'color'],
        };
        $records = $this->catalog($this->records($actor, $permission, $kind), array_intersect_key($filters, array_flip($parents)));
        $history = $kind === 'allocations'
            ? $this->catalog(app(InventoryAccess::class)->scope(SizeAllocation::query(), $actor, $permission), array_intersect_key($filters, array_flip($parents)), true)
            : null;
        $variants = ProductVariant::where(function (Builder $query) use ($records, $history): void {
            $query->whereIn('id', $records->select($records->qualifyColumn('variant_id')));
            if ($history !== null) {
                $query->orWhereIn('id', SizeAllocationItem::select('variant_id')->whereIn('allocation_id', (clone $history)->select('id')));
            }
        });
        $products = Product::where(function (Builder $query) use ($variants, $history): void {
            $query->whereIn('id', (clone $variants)->select('product_id'));
            if ($history !== null) {
                $query->orWhereIn('id', (clone $history)->select('product_id'));
            }
        });
        $query = match ($field) {
            'brand' => Brand::whereIn('id', (clone $products)->select('brand_id')),
            'product', 'product_id' => clone $products,
            'color' => Color::whereIn('id', (clone $variants)->select('color_id')),
            default => (clone $variants)->with(['product:id,name', 'color:id,name', 'size:id,name'])->select(['id', 'product_id', 'color_id', 'size_id', 'sku', 'unallocated_product_id']),
        };
        $attribute = $field === 'variant' ? 'sku' : 'name';
        if ($selected !== null && $selected !== 'none') {
            $query->whereKey($selected);
        }
        $query->when(trim($search) !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($attribute, $search, $field): void {
            $q->where($attribute, 'like', '%'.trim($search).'%');
            if ($field === 'variant') {
                $q->orWhereHas('size', fn (Builder $s) => $s->where('name', 'like', '%'.trim($search).'%'))
                    ->orWhereHas('color', fn (Builder $c) => $c->where('name', 'like', '%'.trim($search).'%'));
            }
        }))->orderBy($attribute)->limit(50);
        $options = $field === 'variant'
            ? $query->get()->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->sku.' · '.$variant->imageLabel()])->all()
            : $query->pluck('name', 'id')->all();
        if (in_array($field, ['brand', 'color'], true)) {
            $label = $field === 'brand' ? __('No brand') : __('No color');
            $hasNone = $field === 'brand' ? (clone $products)->whereNull('brand_id')->exists() : (clone $variants)->whereNull('color_id')->exists();
            if ($hasNone && ($search === '' || mb_stripos($label, $search) !== false)) {
                $options = ['none' => $label] + $options;
            }
        }

        return $selected === null ? $options : array_intersect_key($options, [$selected => true]);
    }

    /** @return array<int, array{label: string, value: string, tone: string, icon: string}> */
    public function cards(Builder $query, string $kind): array
    {
        $q = (clone $query)->reorder()->setEagerLoads([])->select([]);
        if ($kind === 'balances') {
            $row = $q->toBase()->selectRaw('COALESCE(SUM(quantity_on_hand), 0) AS on_hand, COALESCE(SUM(quantity_reserved), 0) AS reserved, COALESCE(SUM(quantity_available), 0) AS available, COUNT(DISTINCT variant_id) AS variants')->first();

            return [$this->card('Matching variants', $row->variants, 'violet', 'heroicon-o-squares-2x2'), $this->card('Total on hand', $row->on_hand, 'amber', 'heroicon-o-cube'), $this->card('Total reserved', $row->reserved, 'blue', 'heroicon-o-lock-closed'), $this->card('Total available', $row->available, 'teal', 'heroicon-o-check-badge')];
        }
        if ($kind === 'movements') {
            $row = $q->toBase()->selectRaw('COUNT(*) AS movements, COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) AS incoming, COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END), 0) AS outgoing, COALESCE(SUM(quantity), 0) AS net')->first();

            return [$this->card('Total movements', $row->movements, 'violet', 'heroicon-o-arrows-right-left'), $this->card('Incoming quantity', $row->incoming, 'teal', 'heroicon-o-arrow-down-tray'), $this->card('Outgoing quantity', $row->outgoing, 'rose', 'heroicon-o-arrow-up-tray'), $this->card('Net quantity change', $row->net, 'blue', 'heroicon-o-chart-bar')];
        }
        if ($kind === 'quarantine') {
            return [$this->card('Quarantined quantity', (clone $q)->sum('quantity'), 'violet', 'heroicon-o-archive-box'), $this->card('Pending inspection quantity', (clone $q)->doesntHave('inspections')->sum('quantity'), 'amber', 'heroicon-o-clock'), $this->card('Observed quantity', (clone $q)->has('inspections')->sum('quantity'), 'blue', 'heroicon-o-clipboard-document-check'), $this->card('Receipt records', (clone $q)->count(), 'teal', 'heroicon-o-document-duplicate')];
        }

        return [$this->card('Allocation records', (clone $q)->count(), 'violet', 'heroicon-o-squares-2x2'), $this->card('Draft', (clone $q)->where('status', 'draft')->count(), 'amber', 'heroicon-o-pencil-square'), $this->card('Posted', (clone $q)->where('status', 'posted')->count(), 'teal', 'heroicon-o-check-badge'), $this->card('Cancelled', (clone $q)->where('status', 'cancelled')->count(), 'rose', 'heroicon-o-x-circle')];
    }

    /** @return array{label: string, value: string, tone: string, icon: string} */
    private function card(string $label, mixed $value, string $tone, string $icon): array
    {
        return ['label' => __($label), 'value' => QuantityFormatter::format((string) $value), 'tone' => $tone, 'icon' => $icon];
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @return array{authorized: bool, sizes: array<int, array<string, mixed>>, totals: array<string, string>}
     */
    public function matrix(?User $actor, array $filters): array
    {
        $access = app(InventoryAccess::class);
        if (! $access->allows($actor, InventoryPermissions::ALLOCATIONS_VIEW) || ! $access->allows($actor, InventoryPermissions::BALANCES)) {
            return ['authorized' => false, 'sizes' => [], 'totals' => []];
        }
        $balances = $this->catalog($this->records($actor, InventoryPermissions::ALLOCATIONS_VIEW, 'allocations'), $filters);
        $rows = $balances->join('product_variants', 'stock_balances.variant_id', '=', 'product_variants.id')->leftJoin('sizes', 'product_variants.size_id', '=', 'sizes.id')
            ->selectRaw('product_variants.size_id, sizes.name, sizes.sort_order, CASE WHEN product_variants.unallocated_product_id IS NULL THEN 0 ELSE 1 END AS unallocated, SUM(quantity_on_hand) AS on_hand, SUM(quantity_reserved) AS reserved, SUM(quantity_available) AS available')
            ->groupBy('product_variants.size_id', 'sizes.name', 'sizes.sort_order', 'unallocated')->orderBy('unallocated')->orderBy('sizes.sort_order')->orderBy('product_variants.size_id')->toBase()->get();
        $totals = ['on_hand' => '0', 'reserved' => '0', 'available' => '0'];
        $sizes = [];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] = (string) BigDecimal::of($totals[$key])->plus($row->{$key});
            }
            $sizes[] = ['label' => $row->unallocated ? __('Unallocated pieces') : ($row->name ?? __('No size')), 'on_hand' => (string) $row->on_hand, 'reserved' => (string) $row->reserved, 'available' => (string) $row->available];
        }

        return ['authorized' => true, 'sizes' => $sizes, 'totals' => $totals];
    }
}
