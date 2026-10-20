<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesFulfillmentService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesReturnQuantityService;
use App\Modules\Sales\Services\SalesReturnService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentSelectors
{
    public function orders(?User $actor, string $kind, string $search = ''): array
    {
        $query = $this->orderQuery($actor, $kind)->with($kind === 'purchase' ? ['location', 'supplier'] : ['location']);
        if ($kind === 'return') {
            $query->whereHas('deliveryItems', fn ($q) => $q->whereHas('salesDelivery', fn ($q) => $q->where('status', 'posted'))
                ->whereRaw("sales_delivery_items.quantity > COALESCE((SELECT SUM(c.quantity) FROM sales_return_quantity_claims c WHERE c.sales_delivery_item_id = sales_delivery_items.id AND c.status IN ('active', 'consumed')), 0)"));
        } elseif ($kind === 'invoice') {
            $query->whereHas('deliveryItems', fn ($q) => $q->whereHas('salesDelivery', fn ($q) => $q->where('status', 'posted'))
                ->whereRaw("sales_delivery_items.quantity > COALESCE((SELECT SUM(m.matched_quantity) FROM sales_invoice_delivery_matches m JOIN sales_invoice_items i ON i.id = m.sales_invoice_item_id JOIN sales_invoices inv ON inv.id = i.sales_invoice_id WHERE m.sales_delivery_item_id = sales_delivery_items.id AND inv.status = 'posted'), 0) + COALESCE((SELECT SUM(a.quantity) FROM sales_return_posted_allocations a JOIN sales_returns r ON r.id = a.sales_return_id WHERE a.sales_delivery_item_id = sales_delivery_items.id AND r.status = 'posted' AND a.sales_invoice_delivery_match_id IS NULL), 0)"));
        } elseif ($kind === 'purchase') {
            $query->whereHas('receipts', fn ($q) => $q->where('status', 'posted')->whereHas('items', fn ($q) => $q
                ->whereRaw("purchase_receipt_items.quantity > COALESCE((SELECT SUM(m.matched_quantity) FROM vendor_bill_receipt_matches m JOIN vendor_bill_items i ON i.id = m.vendor_bill_item_id JOIN vendor_bills b ON b.id = i.vendor_bill_id WHERE m.purchase_receipt_item_id = purchase_receipt_items.id AND b.status = 'posted'), 0)")));
        }
        if ($search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)).'%';
            $query->where(function (Builder $query) use ($pattern, $kind): void {
                $query->where('order_number', 'like', $pattern)
                    ->orWhereHas('location', fn ($q) => $q->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern));
                if (app(DocumentNumberService::class)->installed()) {
                    $query->orWhereHas('documentNumber', fn ($q) => $q->where('number', 'like', $pattern));
                }
                if ($kind === 'purchase') {
                    $query->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', $pattern));
                } else {
                    $query->orWhere('customer_name', 'like', $pattern);
                }
            });
        }

        return $query->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn (Model $record): array => [$record->uuid => $this->orderLabel($record)])->all();
    }

    public function selectedOrder(?User $actor, string $kind, mixed $value): ?Model
    {
        if (! is_scalar($value) || (! ctype_digit((string) $value) && ! Str::isUuid((string) $value))) {
            return null;
        }

        return $this->orderQuery($actor, $kind)->with($kind === 'purchase' ? ['location', 'supplier'] : ['location'])
            ->where(Str::isUuid((string) $value) ? 'uuid' : 'id', $value)->first();
    }

    public function selectedLabel(?User $actor, string $kind, mixed $value): ?string
    {
        $record = $this->selectedOrder($actor, $kind, $value);

        return $record === null ? null : $this->orderLabel($record);
    }

    public function orderValue(?User $actor, string $kind, mixed $value): ?string
    {
        return $this->selectedOrder($actor, $kind, $value)?->uuid;
    }

    private function orderQuery(?User $actor, string $kind): Builder
    {
        return match ($kind) {
            'delivery' => app(SalesDeliveryService::class)->eligibleOrders($actor),
            'invoice' => app(SalesInvoiceService::class)->eligibleOrders($actor),
            'return' => app(SalesReturnService::class)->eligibleOrders($actor),
            'purchase' => app(PurchasingAccess::class)->scopeOrders(PurchaseOrder::query(), $actor)
                ->whereHas('receipts', fn ($q) => $q->where('status', 'posted')),
            default => throw new \InvalidArgumentException('Unsupported document selector.'),
        };
    }

    private function orderLabel(Model $record): string
    {
        $party = $record instanceof PurchaseOrder ? $record->supplier?->name : $record->customer_name;

        return implode(' · ', array_filter([$record->display_reference, $party, $record->location?->name,
            $record->total_amount.' EGP', __('documents.'.$record->status)]));
    }

    /** Catalog filters remain constrained to authorized document source lines. */
    public function sourceProducts(?User $actor, string $kind, mixed $orderValue): Builder
    {
        $order = $this->selectedOrder($actor, $kind, $orderValue);
        $query = Product::query();
        if ($order === null || ! in_array($kind, ['delivery', 'invoice', 'return'], true)) {
            return $query->whereRaw('1 = 0');
        }
        $variants = $order->items()->select('variant_id');

        return $query->whereHas('variants', fn ($query) => $query->whereIn('id', $variants));
    }

    public function lines(?User $actor, string $kind, mixed $order, string $search = '', mixed $brandId = null, mixed $productId = null): array
    {
        return collect($this->lineDetails($actor, $kind, $order, search: $search, brandId: $brandId, productId: $productId))
            ->filter(fn ($line): bool => BigDecimal::of($line['remaining'])->isPositive())
            ->mapWithKeys(fn ($line): array => [$line['uuid'] => $line['label']])->all();
    }

    public function selectedLineLabel(?User $actor, string $kind, mixed $order, mixed $line): ?string
    {
        return $this->lineDetail($actor, $kind, $order, $line)['label'] ?? null;
    }

    public function lineDetail(?User $actor, string $kind, mixed $order, mixed $line): array
    {
        if ($line === null || $line === '') {
            return [];
        }

        return array_values($this->lineDetails($actor, $kind, $order, $line))[0] ?? [];
    }

    private function lineDetails(?User $actor, string $kind, mixed $orderValue, mixed $line = null, string $search = '', mixed $brandId = null, mixed $productId = null): array
    {
        $order = $this->selectedOrder($actor, $kind, $orderValue);
        if ($order === null || ! in_array($kind, ['delivery', 'invoice', 'return'], true)) {
            return [];
        }
        $query = $kind === 'delivery' ? $order->items()->getQuery()
            : SalesDeliveryItem::where('sales_order_id', $order->id)->whereHas('salesDelivery', fn ($q) => $q->where('status', 'posted'));
        $variantPath = $kind === 'delivery' ? 'variant' : 'salesOrderItem.variant';
        $query->when(filled($brandId), fn ($query) => $query->whereHas($variantPath.'.product', fn ($query) => $query->where('brand_id', $brandId)))
            ->when(filled($productId), fn ($query) => $query->whereHas($variantPath, fn ($query) => $query->where('product_id', $productId)));
        $query->with([$variantPath.'.product', $variantPath.'.size', $variantPath.'.color']);
        if ($kind !== 'delivery') {
            $query->with('salesDelivery');
        }
        if ($line !== null) {
            if (! is_scalar($line) || (! ctype_digit((string) $line) && ! Str::isUuid((string) $line))) {
                return [];
            }
            $query->where(Str::isUuid((string) $line) ? 'uuid' : 'id', $line);
        }
        if ($search !== '') {
            $variant = $kind === 'delivery' ? 'variant' : 'salesOrderItem.variant';
            $pattern = '%'.mb_substr($search, 0, 100).'%';
            $query->where(function ($q) use ($pattern, $variant, $kind): void {
                $q->whereHas($variant, fn ($q) => $q->where('sku', 'like', $pattern)->orWhereHas('product', fn ($q) => $q->where('name', 'like', $pattern)));
                if ($kind !== 'delivery') {
                    $q->orWhereHas('salesDelivery', fn ($q) => $q->where('delivery_number', 'like', $pattern));
                }
            });
        }
        $rows = $query->orderBy('id')->limit($line === null ? 50 : 1)->get()->keyBy('id');
        if ($rows->isEmpty()) {
            return [];
        }
        $summary = match ($kind) {
            'delivery' => app(SalesFulfillmentService::class)->quantities($order),
            'return' => app(SalesReturnQuantityService::class)->quantities($rows->modelKeys(), lock: false),
            'invoice' => $this->invoiceAvailability($rows),
        };
        $details = [];
        foreach ($rows as $id => $row) {
            $variant = $kind === 'delivery' ? $row->variant : $row->salesOrderItem->variant;
            if ($kind === 'delivery' && ($variant === null || $variant->isUnallocated() || ! $variant->is_active
                || ! $variant->product?->is_active || $variant->product->status->value !== 'active')) {
                continue;
            }
            $detail = $summary[$id];
            $source = $kind === 'delivery' ? '' : $row->salesDelivery->display_reference.' · ';
            $detail['uuid'] = $row->uuid;
            $detail['brand_id'] = $variant?->product?->brand_id;
            $detail['product_id'] = $variant?->product_id;
            $detail['variant_id'] = $variant?->id;
            $detail['label'] = $source.$variant->displayName().' · '.$order->display_reference.' · '.$order->location->name
                .' · '.__('documents.remaining').': '.$detail['remaining'];
            $details[$id] = $detail;
        }

        return $details;
    }

    /** UI availability hints are aggregated. Creation/posting still verify signed evidence and capacity under locks. */
    private function invoiceAvailability(Collection $rows): array
    {
        $ids = $rows->modelKeys();
        $invoiced = DB::table('sales_invoice_delivery_matches as m')->join('sales_invoice_items as i', 'i.id', '=', 'm.sales_invoice_item_id')
            ->join('sales_invoices as inv', 'inv.id', '=', 'i.sales_invoice_id')->where('inv.status', 'posted')
            ->whereIn('m.sales_delivery_item_id', $ids)->groupBy('m.sales_delivery_item_id')
            ->selectRaw('m.sales_delivery_item_id, SUM(m.matched_quantity) AS quantity')->pluck('quantity', 'sales_delivery_item_id');
        $unbilled = DB::table('sales_return_posted_allocations as a')->join('sales_returns as r', 'r.id', '=', 'a.sales_return_id')
            ->where('r.status', 'posted')->whereNull('a.sales_invoice_delivery_match_id')->whereIn('a.sales_delivery_item_id', $ids)
            ->groupBy('a.sales_delivery_item_id')->selectRaw('a.sales_delivery_item_id, SUM(a.quantity) AS quantity')->pluck('quantity', 'sales_delivery_item_id');
        $summary = [];
        foreach ($rows as $row) {
            $used = (string) BigDecimal::of($invoiced[$row->id] ?? '0')->toScale(4);
            $remaining = BigDecimal::of($row->quantity)->minus($used)->minus($unbilled[$row->id] ?? '0');
            if ($remaining->isNegative()) {
                throw ValidationException::withMessages(['record' => __('returns.reconciliation_invalid')]);
            }
            $summary[$row->id] = ['delivered' => $row->quantity, 'invoiced' => $used, 'remaining' => (string) $remaining->toScale(4)];
        }

        return $summary;
    }

    /** UUIDs are public selection values; existing integer foreign keys and validation remain authoritative. */
    public static function normalizeInput(array $data): array
    {
        foreach (['sales_order_id' => SalesOrder::class, 'purchase_order_id' => PurchaseOrder::class] as $field => $model) {
            if (isset($data[$field]) && is_string($data[$field]) && Str::isUuid($data[$field])) {
                $data[$field] = (int) $model::where('uuid', $data[$field])->value('id');
            }
        }
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as &$line) {
                if (! is_array($line)) {
                    continue;
                }
                foreach (['sales_order_item_id' => SalesOrderItem::class, 'sales_delivery_item_id' => SalesDeliveryItem::class] as $field => $model) {
                    if (isset($line[$field]) && is_string($line[$field]) && Str::isUuid($line[$field])) {
                        $line[$field] = (int) $model::where('uuid', $line[$field])->value('id');
                    }
                }
            }
        }

        return $data;
    }
}
