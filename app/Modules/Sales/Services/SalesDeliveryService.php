<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SalesDeliveryService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesFulfillmentService $fulfillment,
        private readonly PurchaseOrderCalculator $calculator) {}

    public function create(User $actor, array $data, string $submissionId): SalesDelivery
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $data, $submissionId): SalesDelivery {
            ModuleRegistryLock::acquire();
            $data = DocumentSelectors::normalizeInput($data);
            Validator::make($data, ['sales_order_id' => ['required', 'integer', 'min:1']])->validate();
            $order = SalesOrder::whereKey($data['sales_order_id'])->lockForUpdate()->firstOrFail();
            $this->authorize($actor, SalesPermissions::DELIVERIES_CREATE, $order);
            $this->eligible($order);
            [$header, $lines] = $this->prepare($order, $data);
            foreach ($data['items'] as $line) {
                if (! empty($line['id'])) {
                    $this->invalid('Delivery line identity is invalid.');
                }
            }
            $hash = $this->payloadHash($order, $header, $lines);
            $existing = SalesDelivery::where('uuid', strtolower($submissionId))->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->created_by === (int) $actor->id && (int) $existing->sales_order_id === (int) $order->id, 403);
                if ($existing->submission_payload_hash !== $hash) {
                    $this->invalid('This delivery submission was already used for different details.');
                }

                return $existing->load('items');
            }
            $delivery = new SalesDelivery($header + ['sales_order_id' => $order->id, 'location_id' => $order->location_id,
                'delivery_number' => app(DocumentNumberService::class)->allocate((int) $order->location_id, 'SD', $submissionId), 'created_by' => $actor->id, 'status' => 'draft']);
            $delivery->uuid = strtolower($submissionId);
            $delivery->submission_payload_hash = $hash;
            $delivery->save();
            foreach ($lines as $line) {
                $delivery->items()->create($line + ['sales_order_id' => $order->id]);
            }

            return $delivery->refresh()->load('items');
        }, 3);
    }

    public function update(User $actor, SalesDelivery $delivery, array $data, string $revision): SalesDelivery
    {
        return DB::transaction(function () use ($actor, $delivery, $data, $revision): SalesDelivery {
            [$order, $delivery] = $this->lock($actor, $delivery, SalesPermissions::DELIVERIES_UPDATE);
            $this->draft($delivery);
            $this->checkRevision($delivery, $revision);
            $this->eligible($order);
            [$header, $lines] = $this->prepare($order, $data);
            $existing = $delivery->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $kept = [];
            foreach ($lines as $index => $line) {
                $id = $data['items'][$index]['id'] ?? $existing->firstWhere('sales_order_item_id', $line['sales_order_item_id'])?->id;
                if ($id !== null && (! $existing->has($id) || in_array((int) $id, $kept, true)
                    || (int) $existing->get($id)->sales_order_item_id !== $line['sales_order_item_id'])) {
                    $this->invalid('Delivery line identity is invalid.');
                }
                $item = $id === null ? $delivery->items()->make(['sales_order_id' => $order->id]) : $existing->get($id);
                $item->fill($line)->save();
                $kept[] = (int) $item->id;
            }
            $existing->except($kept)->each(fn ($item) => $item->delete());
            $delivery->fill($header)->save();

            return $delivery->refresh()->load('items');
        }, 3);
    }

    public function cancel(User $actor, SalesDelivery $delivery, string $revision): SalesDelivery
    {
        return DB::transaction(function () use ($actor, $delivery, $revision): SalesDelivery {
            [, $delivery] = $this->lock($actor, $delivery, SalesPermissions::DELIVERIES_CANCEL);
            if ($this->isSafelyCancelledDraft($delivery)) {
                return $delivery;
            }
            $this->draft($delivery);
            $this->checkRevision($delivery, $revision);
            if ($delivery->items()->whereNotNull('stock_movement_id')->exists()
                || $delivery->items()->whereHas('invoiceMatches')->exists() || $delivery->items()->whereHas('returnItems')->exists()) {
                $this->invalid('Delivery with stock or financial evidence cannot be cancelled as a draft.');
            }
            DB::table('sales_deliveries')->where('id', $delivery->id)->update(['status' => 'cancelled',
                'cancelled_at' => now(), 'cancelled_by' => $actor->id,
                'cancellation_payload_hash' => $this->contentHash($delivery), 'updated_at' => now()]);

            return $delivery->refresh();
        }, 3);
    }

    public function revision(SalesDelivery $delivery): string
    {
        $fields = ['id', 'uuid', 'delivery_number', 'sales_order_id', 'location_id', 'status', 'delivered_at',
            'posted_at', 'posted_by', 'created_by', 'notes', 'created_at', 'updated_at', 'submission_payload_hash',
            'cancellation_payload_hash', 'cancelled_at', 'cancelled_by', 'posting_payload_hash', 'posting_evidence_hash', 'posting_revision'];
        $header = array_intersect_key($delivery->getAttributes(), array_flip($fields));
        ksort($header);

        return hash('sha256', json_encode([$header, $delivery->items()->orderBy('id')->get()->map->getAttributes()->all()], JSON_THROW_ON_ERROR));
    }

    public function isSafelyCancelledDraft(SalesDelivery $delivery): bool
    {
        return $delivery->status === 'cancelled' && $delivery->posted_at === null && $delivery->posted_by === null
            && $delivery->posting_payload_hash === null && $delivery->posting_evidence_hash === null && $delivery->posting_revision === null
            && $delivery->cancelled_at !== null && $delivery->cancellation_payload_hash !== null
            && hash_equals($delivery->cancellation_payload_hash, $this->contentHash($delivery))
            && ! $this->hasDeliveryItemEvidence($delivery);
    }

    public function eligibleOrders(?User $actor): Builder
    {
        $query = SalesOrder::query()->whereIn('status', ['confirmed', 'partially_fulfilled'])
            ->whereNotNull('confirmed_at')->whereNotNull('confirmation_payload_hash')->whereNull('cancelled_at');
        if (! $this->access->allows($actor, SalesPermissions::DELIVERIES_VIEW)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereHas('location', fn ($query) => $query->where('type', 'branch')->where('is_active', true));

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('location_id', getPermissionsTeamId());
    }

    public function orderOptions(?User $actor): array
    {
        return $this->eligibleOrders($actor)->with('location')->orderByDesc('id')->limit(50)->get()
            ->filter(fn (SalesOrder $order): bool => collect($this->fulfillment->quantities($order))->contains(fn ($line): bool => BigDecimal::of($line['remaining'])->isPositive()))
            ->mapWithKeys(fn (SalesOrder $order): array => [$order->id => $order->display_reference.' — '.$order->location->name])->all();
    }

    public function lineDetails(?User $actor, mixed $orderId, ?array $only = null): array
    {
        if (! is_scalar($orderId) || ! ctype_digit((string) $orderId)) {
            return [];
        }
        $order = $this->eligibleOrders($actor)->find($orderId);
        if ($order === null) {
            return [];
        }
        $quantities = $this->fulfillment->quantities($order);

        return $order->items()->when($only !== null, fn ($q) => $q->whereIn('id', $only))->with(['variant.product', 'variant.size', 'variant.color'])->orderBy('id')->get()
            ->filter(fn ($item): bool => $item->variant !== null && ! $item->variant->isUnallocated() && $item->variant->is_active
                && $item->variant->product?->is_active && $item->variant->product->status->value === 'active')
            ->mapWithKeys(fn ($item): array => [$item->id => $quantities[$item->id] + ['label' => $item->variant->displayName().' | '.$item->variant->sku.' | #'.$item->id]])->all();
    }

    private function prepare(SalesOrder $order, array $data): array
    {
        $data = DocumentSelectors::normalizeInput($data);
        $validated = Validator::make($data, [
            'sales_order_id' => ['sometimes', 'integer', 'in:'.$order->id],
            'location_id' => ['sometimes', 'integer', 'in:'.$order->location_id],
            'notes' => ['nullable', 'string', 'max:16000'], 'delivered_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*' => ['required', 'array'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.sales_order_item_id' => ['required', 'integer', 'distinct'],
        ])->validate();
        foreach (array_diff(array_keys($data), ['sales_order_id', 'location_id', 'notes', 'delivered_at', 'items']) as $field) {
            throw ValidationException::withMessages([$field => __('This field is controlled by delivery workflows.')]);
        }
        $quantities = $this->fulfillment->quantities($order, true);
        $orderItems = $order->items()->orderBy('id')->get()->keyBy('id');
        $variants = ProductVariant::whereKey($orderItems->pluck('variant_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $products = Product::whereKey($variants->pluck('product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $lines = [];
        foreach ($data['items'] as $index => $line) {
            if (array_diff(array_keys($line), ['id', 'sales_order_item_id', 'quantity']) !== []) {
                $this->invalid('This field is controlled by delivery workflows.');
            }
            $item = $orderItems->get($line['sales_order_item_id']);
            $variant = $item === null ? null : $variants->get($item->variant_id);
            $variant?->setRelation('product', $products->get($variant->product_id));
            if ($variant === null || $variant->isUnallocated() || ! $variant->is_active || ! $variant->product?->is_active || $variant->product->status->value !== 'active') {
                $this->invalid('Choose an active saleable order item belonging to this order.');
            }
            $quantity = $this->calculator->decimal($line['quantity'] ?? null, 'items.'.$index.'.quantity', 18, 4);
            if (! BigDecimal::of($quantity)->isPositive() || BigDecimal::of($quantity)->isGreaterThan($quantities[$item->id]['remaining'])) {
                throw ValidationException::withMessages(['items.'.$index.'.quantity' => __('Delivery quantity must be positive and cannot exceed the remaining order quantity.')]);
            }
            $lines[$index] = ['sales_order_item_id' => (int) $item->id, 'quantity' => $quantity];
        }

        return [['notes' => $validated['notes'] ?? null, 'delivered_at' => $validated['delivered_at'] ?? null], $lines];
    }

    private function lock(User $actor, SalesDelivery $delivery, string $permission): array
    {
        ModuleRegistryLock::acquire();
        $orderId = SalesDelivery::whereKey($delivery->id)->value('sales_order_id');
        $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $delivery = $order->deliveries()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $permission, $order);
        if ((int) $delivery->location_id !== (int) $order->location_id) {
            $this->invalid('Delivery branch must match its sales order.');
        }
        $delivery->items()->orderBy('id')->lockForUpdate()->get();

        return [$order, $delivery];
    }

    private function authorize(User $actor, string $permission, SalesOrder $order): void
    {
        $this->access->authorize($actor, SalesPermissions::DELIVERIES_VIEW, (int) $order->location_id);
        $this->access->authorize($actor, $permission, (int) $order->location_id);
        abort_unless(Location::whereKey($order->location_id)->where('type', 'branch')->where('is_active', true)->sharedLock()->exists(), 403);
    }

    private function eligible(SalesOrder $order): void
    {
        if (! in_array($order->status, ['confirmed', 'partially_fulfilled'], true) || $order->confirmed_at === null
            || ! in_array($order->fulfillment_status, ['unfulfilled', 'partial'], true)
            || $order->cancelled_at !== null || $order->cancelled_by !== null || $order->cancellation_payload_hash !== null
            || $order->confirmation_payload_hash === null
            || ! hash_equals($order->confirmation_payload_hash, app(SalesOrderConfirmationService::class)->fingerprint($order))) {
            $this->invalid('Choose a confirmed or partially fulfilled sales order with valid confirmation evidence.');
        }
    }

    private function draft(SalesDelivery $delivery): void
    {
        if ($delivery->status !== 'draft' || $delivery->posted_at !== null || $delivery->posted_by !== null
            || $delivery->posting_payload_hash !== null || $delivery->posting_evidence_hash !== null || $delivery->posting_revision !== null
            || $delivery->cancelled_at !== null || $delivery->cancelled_by !== null || $delivery->cancellation_payload_hash !== null
            || $delivery->items()->whereNotNull('stock_movement_id')->exists()
            || $delivery->items()->whereHas('invoiceMatches')->exists() || $delivery->items()->whereHas('returnItems')->exists()) {
            $this->invalid('Only draft deliveries can be changed.');
        }
    }

    private function checkRevision(SalesDelivery $delivery, string $revision): void
    {
        if (! hash_equals($this->revision($delivery), $revision)) {
            $this->invalid('This delivery changed. Reload before continuing.');
        }
    }

    private function contentHash(SalesDelivery $delivery): string
    {
        $items = $delivery->relationLoaded('items')
            ? $delivery->items->sortBy('id')->values()->map->getAttributes()->all()
            : $delivery->items()->orderBy('id')->get()->map->getAttributes()->all();

        return hash('sha256', json_encode([$delivery->uuid, $delivery->delivery_number, $delivery->created_by,
            $delivery->sales_order_id, $delivery->location_id, $delivery->notes,
            $delivery->delivered_at?->format('Y-m-d H:i:s'), $items], JSON_THROW_ON_ERROR));
    }

    private function hasDeliveryItemEvidence(SalesDelivery $delivery): bool
    {
        if ($delivery->relationLoaded('items') && $delivery->items->every(
            fn ($item): bool => $item->relationLoaded('invoiceMatches') && $item->relationLoaded('returnItems'),
        )) {
            return $delivery->items->contains(
                fn ($item): bool => $item->stock_movement_id !== null
                    || $item->invoiceMatches->isNotEmpty()
                    || $item->returnItems->isNotEmpty(),
            );
        }

        return $delivery->items()->whereNotNull('stock_movement_id')->exists()
            || $delivery->items()->whereHas('invoiceMatches')->exists()
            || $delivery->items()->whereHas('returnItems')->exists();
    }

    private function payloadHash(SalesOrder $order, array $header, array $lines): string
    {
        return hash('sha256', json_encode([$order->id, $header, array_values($lines)], JSON_THROW_ON_ERROR));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['record' => __($message)]);
    }
}
