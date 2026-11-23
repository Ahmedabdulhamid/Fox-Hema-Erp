<?php

namespace App\Modules\Purchasing\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;

class PurchasingDisplay
{
    /** @return array<int|string, string> */
    public function variantOptions(Builder $query): array
    {
        return app(ReadModelCache::class)->remember('catalog', ['purchase-variant-options', $query->toSql(), $query->getBindings()],
            fn (): array => (clone $query)->with(['product', 'color', 'size'])->get()
                ->mapWithKeys(fn ($variant): array => [$variant->id => $variant->displayName()])->all(),
            connection: $query->getConnection());
    }

    /**
     * Display only. Receipt posting and fulfillment call received() directly under locks.
     *
     * @return array<int, string>
     */
    public function received(User $actor, PurchaseOrder $order): array
    {
        app(PurchasingAccess::class)->authorize($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $order->location_id);

        return app(ReadModelCache::class)->remember('purchasing', ['received-display', $actor->id, $order->id, $order->location_id],
            fn (): array => app(PurchaseReceiptService::class)->received($order), config('read_cache.report_ttl'), $order->getConnection());
    }

    /** @return array{ordered: string, received: string, remaining: string, receipt_count: int} */
    public function receiptSummary(User $actor, PurchaseOrder $order): array
    {
        app(PurchasingAccess::class)->authorize($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $order->location_id);

        return app(ReadModelCache::class)->remember('purchasing', ['receipt-summary-display', $actor->id, $order->id, $order->location_id],
            fn (): array => app(PurchaseReceiptService::class)->summary($order),
            config('read_cache.report_ttl'), $order->getConnection());
    }
}
