<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(private readonly PurchasingAccess $access, private readonly PurchaseOrderCalculator $calculator) {}

    /** The submission UUID is durable retry identity, reused by the caller for duplicate requests. */
    public function create(User $actor, array $data, string $submissionId): PurchaseOrder
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();
        $submissionId = strtolower($submissionId);

        return DB::transaction(function () use ($actor, $data, $submissionId): PurchaseOrder {
            ModuleRegistryLock::acquire();
            $this->authorize($actor, PurchasingPermissions::ORDERS_CREATE);
            $this->rejectProtectedFields($data);
            [$header, $lines] = $this->prepare($actor, $data);
            $existing = PurchaseOrder::where('uuid', $submissionId)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->authorize($actor, PurchasingPermissions::ORDERS_CREATE, $existing);
                abort_unless((int) $existing->created_by === (int) $actor->id, 403);
                $fields = array_keys(reset($lines) ?: []);
                $existingLines = $existing->items()->orderBy('id')->get()->map(function (PurchaseOrderItem $item) use ($fields): array {
                    $line = [];
                    foreach ($fields as $field) {
                        $line[$field] = $item->{$field};
                    }

                    return $line;
                })->all();
                $existingHeader = [];
                foreach ($header as $field => $value) {
                    $existingHeader[$field] = in_array($field, ['order_date', 'expected_date'], true) ? $existing->{$field}?->format('Y-m-d') : $existing->{$field};
                }
                if ($existingHeader !== $header || $existingLines !== array_values($lines)) {
                    $this->invalid('submission_id', 'This submission identity was already used for different order details.');
                }

                return $existing;
            }
            foreach ($data['items'] as $index => $line) {
                if (! empty($line['id'])) {
                    $this->invalid('items.'.$index.'.id', 'New orders cannot reuse existing line identities.');
                }
            }
            $order = new PurchaseOrder($header + $this->calculator->totals($lines));
            $order->uuid = $submissionId;
            $order->order_number = app(DocumentNumberService::class)->allocate((int) $header['location_id'], 'PO', $submissionId);
            $order->status = 'draft';
            $order->created_by = $actor->id;
            $order->save();
            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            return $order->refresh()->load('items');
        }, 3);
    }

    public function update(User $actor, PurchaseOrder $order, array $data, string $revision): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order, $data, $revision): PurchaseOrder {
            ModuleRegistryLock::acquire();
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, PurchasingPermissions::ORDERS_UPDATE, $order);
            $this->checkRevision($order, $revision);
            if (! in_array($order->status, ['draft', 'rfq'], true)) {
                $this->invalid('status', 'Only draft and RFQ orders can be edited.');
            }
            $this->rejectProtectedFields($data);
            [$header, $lines] = $this->prepare($actor, $data);
            $existing = $order->items()->lockForUpdate()->get()->keyBy('id');
            $kept = [];
            foreach ($lines as $index => $line) {
                $id = $data['items'][$index]['id'] ?? null;
                if ($id !== null && (! $existing->has($id) || in_array((int) $id, $kept, true))) {
                    $this->invalid('items.'.$index.'.id', 'Line identity is duplicated or does not belong to this order.');
                }
                $item = $id === null ? new PurchaseOrderItem : $existing->get($id);
                $item->fill($line);
                $order->items()->save($item);
                $kept[] = (int) $item->id;
            }
            $order->items()->whereNotIn('id', $kept)->get()->each(fn (PurchaseOrderItem $item) => $item->delete());
            $order->fill($header + $this->calculator->totals($lines))->save();

            return $order->refresh()->load('items');
        }, 3);
    }

    public function confirm(User $actor, PurchaseOrder $order, string $revision): PurchaseOrder
    {
        return $this->transition($actor, $order, 'confirmed', $revision);
    }

    public function markRfq(User $actor, PurchaseOrder $order, string $revision): PurchaseOrder
    {
        return $this->transition($actor, $order, 'rfq', $revision);
    }

    public function resetDraft(User $actor, PurchaseOrder $order, string $revision): PurchaseOrder
    {
        return $this->transition($actor, $order, 'draft', $revision);
    }

    public function cancel(User $actor, PurchaseOrder $order, string $revision): PurchaseOrder
    {
        return $this->transition($actor, $order, 'cancelled', $revision);
    }

    /** Fingerprint includes line identity and values, so same-second edits are detected. */
    public function revision(PurchaseOrder $order): string
    {
        return hash('sha256', json_encode([$order->getAttributes(), $order->items()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    public function history(?User $actor): Builder
    {
        return PurchaseOrderItem::query()->with(['variant.product', 'variant.color', 'variant.size', 'purchaseOrder.supplier', 'purchaseOrder.location'])
            ->whereHas('purchaseOrder', fn (Builder $query) => $this->access->scopeOrders($query, $actor));
    }

    public function variants(?User $actor, bool $activeOnly = true): Builder
    {
        $query = ProductVariant::query()->with('product');
        if (! $this->access->allows($actor, PurchasingPermissions::ORDERS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $activeOnly ? $query->where('is_active', true)->whereHas('product', fn (Builder $products) => $products->where('is_active', true)->where('status', 'active')) : $query;
    }

    public function suppliers(?User $actor, bool $activeOnly = true): Builder
    {
        $query = Supplier::query();
        if (! $this->access->allows($actor, PurchasingPermissions::ORDERS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $activeOnly ? $query->where('is_active', true) : $query;
    }

    private function authorize(User $actor, string $permission, ?PurchaseOrder $order = null): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->access->authorize($actor, PurchasingPermissions::ORDERS_VIEW, $order === null ? null : (int) $order->location_id);
        $this->access->authorize($actor, $permission, $order === null ? null : (int) $order->location_id);
    }

    private function prepare(User $actor, array $data): array
    {
        $validated = Validator::make($data, [
            'supplier_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'order_date' => ['nullable', 'date_format:Y-m-d'], 'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:16000'], 'items' => ['present', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'integer', 'min:1'], 'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.product_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'items.*.receiving_mode' => ['sometimes', 'required', 'in:unknown,known,standard'],
            'items.*.notes' => ['nullable', 'string', 'max:16000'],
        ])->validate();
        $this->access->authorize($actor, PurchasingPermissions::ORDERS_VIEW, (int) $validated['location_id']);
        if (! $this->access->locations($actor)->whereKey($validated['location_id'])->lockForUpdate()->first()) {
            $this->invalid('location_id', 'Choose an authorized active location.');
        }
        if (! Supplier::whereKey($validated['supplier_id'])->where('is_active', true)->lockForUpdate()->first()) {
            $this->invalid('supplier_id', 'Choose an active supplier.');
        }
        foreach ($data['items'] as $index => &$line) {
            if (array_key_exists('product_id', $line) || array_key_exists('receiving_mode', $line)) {
                $line['variant_id'] = app(PurchaseOrderProductSelection::class)->resolve($actor, $line, 'items.'.$index.'.');
            } elseif (empty($line['variant_id'])) {
                $this->invalid('items.'.$index.'.variant_id', 'Choose an active variant of an active product.');
            }
        }
        unset($line);
        $variantIds = array_column($data['items'], 'variant_id');
        $variants = $this->variants($actor)->whereKey($variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $productIds = $variants->pluck('product_id')->unique()->sort()->values();
        $products = Product::whereKey($productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        foreach ($data['items'] as $index => $line) {
            $variant = $variants->get($line['variant_id']);
            if ($variant === null || ! $products->get($variant->product_id)?->is_active || $products->get($variant->product_id)?->status->value !== 'active') {
                $this->invalid('items.'.$index.'.variant_id', 'Choose an active variant of an active product.');
            }
            $variant->setRelation('product', $products->get($variant->product_id));
            $lines[$index] = ['variant_id' => (int) $line['variant_id'], 'notes' => $line['notes'] ?? null]
                + app(PurchaseLinePricing::class)->line($variant, $line, 'items.'.$index.'.');
        }

        return [['supplier_id' => (int) $validated['supplier_id'], 'location_id' => (int) $validated['location_id'],
            'order_date' => $validated['order_date'] ?? null, 'expected_date' => $validated['expected_date'] ?? null,
            'notes' => $validated['notes'] ?? null], $lines];
    }

    private function transition(User $actor, PurchaseOrder $order, string $target, string $revision): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order, $target, $revision): PurchaseOrder {
            ModuleRegistryLock::acquire();
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $permission = match ($target) {
                'confirmed' => PurchasingPermissions::ORDERS_CONFIRM,
                'cancelled' => PurchasingPermissions::ORDERS_CANCEL,
                default => PurchasingPermissions::ORDERS_UPDATE,
            };
            $this->authorize($actor, $permission, $order);
            if ($order->status === $target) {
                return $order;
            }
            $this->checkRevision($order, $revision);
            $from = match ($target) {
                'draft' => ['rfq'], 'rfq' => ['draft'], 'confirmed' => ['draft', 'rfq'],
                'cancelled' => ['draft', 'rfq', 'confirmed'],
            };
            if (! in_array($order->status, $from, true)) {
                $this->invalid('status', 'This lifecycle transition is not available.');
            }
            if ($target === 'cancelled' && ($order->receipts()->where('status', '!=', 'cancelled')->exists()
                || $order->receiptItems()->whereNotNull('stock_movement_id')->exists() || $order->vendorBills()->exists())) {
                $this->invalid('status', 'Cancel open draft receipts before cancelling their PO. Posted receiving or billing blocks cancellation.');
            }
            if ($target === 'confirmed') {
                $items = $order->items()->orderBy('id')->lockForUpdate()->get();
                if ($items->isEmpty()) {
                    $this->invalid('items', 'At least one purchase order line is required for confirmation.');
                }
                $data = Arr::only($order->toArray(), ['supplier_id', 'location_id', 'notes']);
                $data['order_date'] = $order->order_date?->format('Y-m-d');
                $data['expected_date'] = $order->expected_date?->format('Y-m-d');
                $data['items'] = $items->map(fn ($item): array => ['discount_id' => $item->commercial_snapshot['discount']['id'] ?? null, 'tax_ids' => array_column($item->commercial_snapshot['taxes'] ?? [], 'id')] + Arr::only($item->toArray(), ['variant_id', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'notes']))->all();
                [, $lines] = $this->prepare($actor, $data);
                foreach (array_values($lines) as $index => $line) {
                    app(PurchaseLinePricing::class)->assertPostable($line, 'items.'.$index.'.');
                    if ($this->normalizeSnapshot($items[$index]->commercial_snapshot) !== $this->normalizeSnapshot($line['commercial_snapshot'])) {
                        $this->invalid('items', 'Configured purchase pricing changed or evidence is missing. Save the draft before confirmation.');
                    }
                    foreach ($this->calculator->line($line) as $field => $value) {
                        if ($items[$index]->{$field} !== $value) {
                            $this->invalid('items', 'Stored line totals do not reconcile. Save the draft before confirmation.');
                        }
                    }
                }
                foreach ($this->calculator->totals($lines) as $field => $value) {
                    if ($order->{$field} !== $value) {
                        $this->invalid($field, 'Stored header totals do not reconcile. Save the draft before confirmation.');
                    }
                }
                $order->confirmed_by = $actor->id;
                $order->confirmed_at = now();
            }
            $order->status = $target;
            $order->save();
            if ($target === 'confirmed') {
                app(PurchaseReceiptService::class)->prepareAfterConfirmation($actor, $order);
            }

            return $order->refresh();
        }, 3);
    }

    /** JSON object key order may change during database storage; list order and scalar types remain significant. */
    private function normalizeSnapshot(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeSnapshot($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function rejectProtectedFields(array $data): void
    {
        foreach (['status', 'order_number', 'created_by', 'confirmed_by', 'confirmed_at', 'uuid', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->invalid($field, 'This field is controlled by purchase order operations.');
            }
        }
    }

    private function checkRevision(PurchaseOrder $order, string $revision): void
    {
        if (! hash_equals($this->revision($order), $revision)) {
            $this->invalid('revision', 'This purchase order changed. Reload before continuing.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
