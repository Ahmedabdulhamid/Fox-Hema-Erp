<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderService
{
    /** Share decimal totals without changing purchasing behavior. */
    public function __construct(private readonly SalesAccess $access, private readonly PurchaseOrderCalculator $calculator) {}

    public function create(User $actor, array $data, string $submissionId): SalesOrder
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();
        $submissionId = strtolower($submissionId);

        return DB::transaction(function () use ($actor, $data, $submissionId): SalesOrder {
            ModuleRegistryLock::acquire();
            $this->authorize($actor, SalesPermissions::CREATE);
            $existing = SalesOrder::where('uuid', $submissionId)->lockForUpdate()->first();
            [$header, $lines] = $this->prepare($actor, $data);
            foreach ($data['items'] as $index => $line) {
                if (! empty($line['id'])) {
                    $this->invalid('items.'.$index.'.id', 'New orders cannot reuse existing line identities.');
                }
            }
            if ($existing !== null) {
                $this->authorize($actor, SalesPermissions::CREATE, $existing);
                abort_unless((int) $existing->created_by === (int) $actor->id, 403);
                $storedHeader = [];
                foreach ($header as $field => $value) {
                    $storedHeader[$field] = $field === 'ordered_at' ? $existing->ordered_at?->format('Y-m-d H:i:s') : $existing->{$field};
                }
                $storedLines = $existing->items()->orderBy('id')->get()->map(function (SalesOrderItem $item) use ($lines): array {
                    $values = [];
                    foreach (array_keys(reset($lines)) as $field) {
                        $values[$field] = $item->{$field};
                    }

                    return $values;
                })->all();
                if ($existing->status !== 'draft' || $storedHeader !== $header || $storedLines !== array_values($lines)) {
                    $this->invalid('submission_id', 'This submission identity was already used for different order details.');
                }

                return $existing->load('items');
            }
            $order = new SalesOrder($header + $this->calculator->totals($lines));
            $order->uuid = $submissionId;
            $order->order_number = app(DocumentNumberService::class)->allocate((int) $header['location_id'], 'SO', $submissionId);
            $order->status = 'draft';
            $order->fulfillment_status = 'unfulfilled';
            $order->channel = 'manual';
            $order->created_by = $actor->id;
            $order->save();
            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            return $order->refresh()->load('items');
        }, 3);
    }

    public function update(User $actor, SalesOrder $order, array $data, string $revision): SalesOrder
    {
        return DB::transaction(function () use ($actor, $order, $data, $revision): SalesOrder {
            ModuleRegistryLock::acquire();
            $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, SalesPermissions::UPDATE, $order);
            if ($order->status !== 'draft' || $order->fulfillment_status !== 'unfulfilled'
                || $order->deliveries()->exists() || $order->invoices()->exists() || $order->returns()->exists()) {
                $this->invalid('status', 'Only unfulfilled draft sales orders without downstream documents can be edited.');
            }
            if (! hash_equals($this->revision($order), $revision)) {
                $this->invalid('revision', 'This sales order changed. Reload before continuing.');
            }
            [$header, $lines] = $this->prepare($actor, $data);
            if ($header['location_id'] !== (int) $order->location_id) {
                $this->invalid('location_id', 'The branch of an existing sales order cannot be changed.');
            }
            $existing = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $kept = [];
            foreach ($lines as $index => $line) {
                $id = $data['items'][$index]['id'] ?? null;
                if ($id !== null && (! $existing->has($id) || in_array((int) $id, $kept, true))) {
                    $this->invalid('items.'.$index.'.id', 'Line identity is duplicated or does not belong to this order.');
                }
                $item = $id === null ? new SalesOrderItem : $existing->get($id);
                $item->fill($line);
                $order->items()->save($item);
                $kept[] = (int) $item->id;
            }
            $order->items()->whereNotIn('id', $kept)->get()->each(fn (SalesOrderItem $item) => $item->delete());
            $order->fill($header + $this->calculator->totals($lines))->save();

            return $order->refresh()->load('items');
        }, 3);
    }

    public function revision(SalesOrder $order): string
    {
        return hash('sha256', json_encode([$order->getAttributes(), $order->items()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    /** Validate saved prices and eligibility without repricing or rewriting the draft. */
    public function validateForConfirmation(User $actor, SalesOrder $order): array
    {
        $items = $order->items()->with('variant')->orderBy('id')->lockForUpdate()->get();
        $data = Arr::only($order->toArray(), ['location_id', 'customer_name', 'customer_phone', 'notes']);
        $data['ordered_at'] = $order->ordered_at?->format('Y-m-d H:i:s');
        $data['items'] = $items->map(fn (SalesOrderItem $item): array => ['product_id' => $item->variant?->product_id,
            'tax_ids' => array_column($item->commercial_snapshot['taxes'] ?? [], 'id'),
            'discount_id' => $item->commercial_snapshot['discount']['id'] ?? null]
            + Arr::only($item->toArray(), ['id', 'variant_id', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'notes']))->all();
        [, $lines] = $this->prepare($actor, $data);
        foreach (array_values($lines) as $index => $line) {
            if ($items[$index]->commercial_snapshot !== $line['commercial_snapshot']) {
                $this->invalid('record', 'Configured pricing changed or evidence is missing. Save the draft before confirmation.');
            }
            foreach (['quantity', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                if ($items[$index]->{$field} !== $line[$field]) {
                    $this->invalid('record', 'Stored sales order amounts do not reconcile. Save the draft before confirmation.');
                }
            }
        }
        foreach ($this->calculator->totals($lines) as $field => $value) {
            if ($order->{$field} !== $value) {
                $this->invalid('record', 'Stored sales order amounts do not reconcile. Save the draft before confirmation.');
            }
        }

        return $lines;
    }

    public function formItems(SalesOrder $order): array
    {
        return $order->items()->with(['variant.stockBalances' => fn ($query) => $query->where('location_id', $order->location_id)])
            ->orderBy('id')->get()->map(fn (SalesOrderItem $item): array => [
                'product_id' => $item->variant->product_id,
                'brand_id' => $item->variant->product?->brand_id,
                'tax_ids' => array_column($item->commercial_snapshot['taxes'] ?? [], 'id'),
                'discount_id' => $item->commercial_snapshot['discount']['id'] ?? null,
                'available_quantity' => $item->variant->stockBalances->first()?->quantity_available ?? '0.0000',
            ] + Arr::only($item->toArray(),
                ['id', 'variant_id', 'description', 'quantity', 'unit_price', 'notes']))->all();
    }

    private function authorize(User $actor, string $permission, ?SalesOrder $order = null): void
    {
        $locationId = $order === null ? null : (int) $order->location_id;
        $this->access->authorize($actor, SalesPermissions::VIEW, $locationId);
        $this->access->authorize($actor, $permission, $locationId);
    }

    private function prepare(User $actor, array $data): array
    {
        foreach (['status', 'fulfillment_status', 'order_number', 'created_by', 'confirmed_by', 'confirmed_at',
            'uuid', 'channel', 'external_order_id', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
            'cancelled_at', 'cancelled_by', 'confirmation_payload_hash', 'cancellation_payload_hash'] as $field) {
            if (array_key_exists($field, $data)) {
                $this->invalid($field, 'This field is controlled by sales order operations.');
            }
        }
        $validated = Validator::make($data, [
            'location_id' => ['required', 'integer', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'ordered_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'notes' => ['nullable', 'string', 'max:16000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['required', 'integer', 'min:1'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.notes' => ['nullable', 'string', 'max:16000'],
        ])->validate();
        $locationId = (int) $validated['location_id'];
        $this->access->authorize($actor, SalesPermissions::VIEW, $locationId);
        $branch = $this->access->branches($actor)->whereKey($locationId)->sharedLock()->first();
        if ($branch === null) {
            $this->invalid('location_id', 'Choose an authorized active branch.');
        }
        // Shared catalog locks remain compatible with inventory movement/upsert foreign-key reads.
        $variants = ProductVariant::whereKey(array_column($data['items'], 'variant_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $products = Product::whereKey($variants->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $variants->load(['size', 'color']);
        $lines = [];
        foreach ($data['items'] as $index => $line) {
            $variant = $variants->get($line['variant_id']);
            $product = $variant === null ? null : $products->get($variant->product_id);
            if ($variant === null || $variant->isUnallocated() || ! $variant->is_active || $product === null
                || ! $product->is_active || $product->status->value !== 'active' || (int) $line['product_id'] !== (int) $product->id) {
                $this->invalid('items.'.$index.'.variant_id', 'Choose an active saleable variant belonging to the selected product.');
            }
            $variant->setRelation('product', $product);
            if (! array_key_exists('unit_price', $line)) {
                $line['unit_price'] = app(SellingPriceResolver::class)->resolve($variant);
            }
            $lines[$index] = ['variant_id' => (int) $variant->id, 'description' => $line['description'] ?? Str::limit($variant->displayName(), 255, ''),
                'notes' => $line['notes'] ?? null] + app(SalesLinePricing::class)->line($variant, $line, 'items.'.$index.'.');
        }

        app(SalesStockAvailability::class)->validate($actor, $branch, $lines, $variants);

        return [['location_id' => $locationId, 'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null, 'ordered_at' => $validated['ordered_at'] ?? null,
            'notes' => $validated['notes'] ?? null], $lines];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
