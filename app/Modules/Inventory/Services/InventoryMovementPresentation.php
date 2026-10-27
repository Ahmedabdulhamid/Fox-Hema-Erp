<?php

namespace App\Modules\Inventory\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InventoryMovementPresentation
{
    public function scoped(Builder $query, User $actor, ?int $product = null, ?int $location = null): Builder
    {
        $access = app(InventoryAccess::class);
        $access->authorize($actor, InventoryPermissions::MOVEMENTS, $location);

        return $access->scope(clone $query, $actor, InventoryPermissions::MOVEMENTS)
            ->when($product !== null, fn (Builder $query): Builder => $query->whereHas('variant', fn (Builder $query): Builder => $query->where('product_id', $product)))
            ->when($location !== null, fn (Builder $query): Builder => $query->where('stock_movements.location_id', $location));
    }

    /** @return LengthAwarePaginator<int, \stdClass> */
    public function products(Builder $query, User $actor, int $page = 1, string $sort = 'latest', int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        $source = $this->scoped($query, $actor)->reorder()->setEagerLoads([])
            ->join('product_variants as movement_variant', 'movement_variant.id', '=', 'stock_movements.variant_id')
            ->select(['movement_variant.product_id', 'stock_movements.location_id', 'stock_movements.variant_id', 'stock_movements.quantity', 'stock_movements.created_at']);
        $groups = DB::query()->fromSub($source->toBase(), 'movement_rows')->select(['product_id', 'location_id'])
            ->selectRaw('MIN(variant_id) AS id, COUNT(*) AS movement_count, COUNT(DISTINCT variant_id) AS variant_count, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS incoming, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS outgoing, MAX(created_at) AS latest_at')
            ->groupBy('product_id', 'location_id');
        [$column, $direction] = match ($sort) {
            'incoming' => ['incoming', 'desc'], 'outgoing' => ['outgoing', 'desc'], 'oldest' => ['latest_at', 'asc'], default => ['latest_at', 'desc'],
        };
        $rows = $groups->orderBy($column, $direction)->orderBy('product_id')->orderBy('location_id')
            ->paginate(in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25, ['*'], $pageName, max(1, $page))->onEachSide(1);
        $visibleRows = DB::query()->fromSub($source->toBase(), 'visible_movements')
            ->whereIn('product_id', $rows->pluck('product_id'))->whereIn('location_id', $rows->pluck('location_id'))
            ->select(['product_id', 'location_id', 'variant_id'])->distinct()->get();
        $variants = ProductVariant::whereIn('id', $visibleRows->pluck('variant_id'))
            ->with(['product.images', 'product.brand', 'images', 'color', 'size'])->get()->keyBy('id');
        $locations = Location::whereIn('id', $rows->pluck('location_id'))->pluck('name', 'id');
        foreach ($rows as $row) {
            $row->variant = $variants->get($row->id);
            $row->location_name = $locations->get($row->location_id);
            $matchingIds = $visibleRows->where('product_id', $row->product_id)->where('location_id', $row->location_id)->pluck('variant_id');
            $row->variants = $variants->only($matchingIds->all())->values();
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function history(Builder $query, User $actor, int $product, int $location, int $page = 1): array
    {
        $query = $this->scoped($query, $actor, $product, $location);
        abort_unless((clone $query)->exists(), 404);
        $summary = (clone $query)->reorder()->setEagerLoads([])->select([])->toBase()
            ->selectRaw('COUNT(*) AS count, SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) AS incoming, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) AS outgoing, SUM(quantity) AS net')->first();
        $variants = ProductVariant::whereIn('id', (clone $query)->reorder()->select('stock_movements.variant_id'))
            ->with(['product.images', 'product.brand', 'images', 'color', 'size'])->orderBy('sku')->get();

        $movements = $query->reorder()->orderByDesc('stock_movements.created_at')->orderByDesc('stock_movements.id')
            ->paginate(25, ['stock_movements.*'], 'movementHistoryPage', max(1, $page));
        foreach ($movements as $movement) {
            $movement->setRelation('inventorySourceDocument', $this->sourceDocument($movement, $actor));
        }
        $canViewBalance = app(InventoryAccess::class)->allows($actor, InventoryPermissions::BALANCES, $location);
        $balance = $canViewBalance ? StockBalance::where('location_id', $location)->whereIn('variant_id', $variants->pluck('id'))
            ->selectRaw('COUNT(DISTINCT variant_id) AS variants, SUM(quantity_on_hand) AS quantity')->first() : null;

        return [
            'productVariant' => $variants->first(), 'variants' => $variants,
            'location' => Location::findOrFail($location), 'summary' => $summary,
            'canViewBalance' => $canViewBalance,
            'currentBalance' => $balance && (int) $balance->variants === $variants->count() ? $balance->quantity : null,
            'movements' => $movements,
        ];
    }

    private function sourceDocument(StockMovement $movement, User $actor): ?Model
    {
        $referenceClass = $movement->reference_type ? (Relation::getMorphedModel($movement->reference_type) ?? $movement->reference_type) : null;
        if (! in_array($referenceClass, [PurchaseReceipt::class, PurchaseReceiptItem::class, SalesDelivery::class, SalesDeliveryItem::class, SalesReturn::class, SalesReturnItem::class], true)) {
            return null;
        }
        $reference = $movement->reference;
        $document = match (true) {
            $reference instanceof PurchaseReceiptItem => $reference->purchaseReceipt,
            $reference instanceof SalesDeliveryItem => $reference->salesDelivery,
            $reference instanceof SalesReturnItem => $reference->salesReturn,
            default => $reference,
        };

        return $document !== null && Gate::forUser($actor)->allows('view', $document) ? $document : null;
    }
}
