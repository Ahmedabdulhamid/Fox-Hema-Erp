<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesReturnQuantityService $quantities,
        private readonly SalesReturnAllocationService $allocations, private readonly PurchaseOrderCalculator $calculator) {}

    public function create(User $actor, array $data, string $submissionId): SalesReturn
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $data, $submissionId): SalesReturn {
            ModuleRegistryLock::acquire();
            $data = DocumentSelectors::normalizeInput($data);
            Validator::make($data, ['sales_order_id' => 'required|integer|min:1'])->validate();
            $order = SalesOrder::whereKey($data['sales_order_id'])->lockForUpdate()->firstOrFail();
            $this->authorize($actor, SalesPermissions::RETURNS_CREATE, (int) $order->location_id);
            [$header, $input] = $this->input($order, $data);
            foreach ($input as $line) {
                if (isset($line['id'])) {
                    $this->invalid('source_invalid');
                }
            }
            $hash = hash('sha256', json_encode([$header, $input], JSON_THROW_ON_ERROR));
            $existing = SalesReturn::where('uuid', strtolower($submissionId))->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->created_by === (int) $actor->id && (int) $existing->sales_order_id === (int) $order->id, 403);
                if ($existing->submission_payload_hash !== $hash) {
                    $this->invalid('changed');
                }

                return $existing->load('items.invoiceAllocations');
            }
            $lines = $this->prepare($order, $input, $header['sales_invoice_id']);
            $now = now();
            $id = DB::table('sales_returns')->insertGetId($header + [
                'uuid' => strtolower($submissionId), 'sales_order_id' => $order->id, 'location_id' => $order->location_id,
                'return_number' => app(DocumentNumberService::class)->allocate((int) $order->location_id, 'SR', $submissionId), 'created_by' => $actor->id,
                'status' => 'draft', 'submission_payload_hash' => $hash, 'revision_number' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $return = SalesReturn::findOrFail($id);
            $this->saveLines($return, $lines);
            $this->audit($actor, $return, 'sales_return_created');

            return $return->refresh()->load('items.invoiceAllocations');
        }, 3);
    }

    public function update(User $actor, SalesReturn $return, array $data, string $revision): SalesReturn
    {
        return DB::transaction(function () use ($actor, $return, $data, $revision): SalesReturn {
            [$order, $return] = $this->lock($actor, $return, SalesPermissions::RETURNS_UPDATE);
            $this->draft($return);
            $this->checkRevision($return, $revision);
            $this->assertNoDownstream($return);
            [$header, $input] = $this->input($order, $data);
            if ($header['sales_invoice_id'] !== $return->sales_invoice_id) {
                $this->invalid('source_invalid');
            }
            $lines = $this->prepare($order, $input, $header['sales_invoice_id'], $return->id);
            $existing = $return->items()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $kept = [];
            foreach ($lines as $line) {
                if (isset($line['id'])) {
                    $item = $existing->get($line['id']);
                    if ($item === null || in_array($item->id, $kept, true)
                        || (int) $item->sales_delivery_item_id !== $line['sales_delivery_item_id']
                        || $item->sales_invoice_item_id !== $line['sales_invoice_item_id']) {
                        $this->invalid('source_invalid');
                    }
                    $kept[] = $item->id;
                }
            }
            DB::table('sales_return_invoice_allocations')->where('sales_return_id', $return->id)->delete();
            foreach ($existing as $item) {
                if (! in_array($item->id, $kept, true)) {
                    DB::table('sales_return_items')->where('id', $item->id)->delete();
                }
            }
            $this->saveLines($return, $lines);
            DB::table('sales_returns')->where('id', $return->id)->update($header + [
                'revision_number' => $return->revision_number + 1, 'updated_at' => now(),
            ]);
            $this->audit($actor, $return->refresh(), 'sales_return_updated');

            return $return->load('items.invoiceAllocations');
        }, 3);
    }

    /** Same order-first lock convention as invoice and delivery posting; no stock locks in Phase 5A. */
    public function lock(User $actor, SalesReturn $return, string $permission): array
    {
        ModuleRegistryLock::acquire();
        $orderId = SalesReturn::whereKey($return->id)->value('sales_order_id');
        if ($orderId === null) {
            $this->invalid('legacy_allocation');
        }
        $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
        app(SalesDocumentLocks::class)->acquire($order);
        $return = $order->returns()->whereKey($return->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $permission, (int) $return->location_id);
        if ((int) $return->location_id !== (int) $order->location_id || $return->submission_payload_hash === null) {
            $this->invalid('source_invalid');
        }
        $order->items()->orderBy('id')->lockForUpdate()->get();
        $order->deliveries()->orderBy('id')->lockForUpdate()->get();
        $order->deliveryItems()->orderBy('id')->lockForUpdate()->get();
        $return->items()->orderBy('id')->lockForUpdate()->get();

        return [$order, $return];
    }

    public function authorize(User $actor, string $permission, int $locationId): void
    {
        $this->access->authorize($actor, SalesPermissions::RETURNS_VIEW, $locationId);
        $this->access->authorize($actor, $permission, $locationId);
        abort_unless(Location::whereKey($locationId)->where('type', 'branch')->where('is_active', true)->sharedLock()->exists(), 403);
    }

    public function revision(SalesReturn $return): string
    {
        return hash('sha256', $this->fingerprint($return).':'.$return->revision_number.':'.$return->status);
    }

    /** Only immutable business content is signed; approval/cancellation evidence is verified separately. */
    public function fingerprint(SalesReturn $return): string
    {
        $header = Arr::only($return->getAttributes(), ['id', 'uuid', 'return_number', 'sales_order_id', 'sales_invoice_id',
            'location_id', 'return_date', 'reason', 'notes', 'created_by', 'submission_payload_hash', 'revision_number']);
        $lines = $return->items()->with('invoiceAllocations')->orderBy('id')->get()->map(fn ($item) => [
            Arr::except($item->getAttributes(), ['updated_at']), $item->invoiceAllocations->sortBy('id')->map->getAttributes()->values()->all(),
        ])->all();

        return hash('sha256', json_encode([$header, $lines], JSON_THROW_ON_ERROR));
    }

    public function checkRevision(SalesReturn $return, string $revision): void
    {
        if (! hash_equals($this->revision($return), $revision)) {
            $this->invalid('changed');
        }
    }

    public function draft(SalesReturn $return): void
    {
        if ($return->status !== 'draft' || $return->approved_at !== null || $return->approved_by !== null
            || $return->approval_payload_hash !== null || $return->approval_revision !== null || $return->approval_evidence_hash !== null
            || $return->cancelled_at !== null || $return->cancelled_by !== null || $return->cancellation_payload_hash !== null
            || $return->posted_at !== null || $return->posted_by !== null || $return->quantityClaims()->exists()) {
            $this->invalid('draft_only');
        }
    }

    public function validateSources(SalesOrder $order, SalesReturn $return): void
    {
        $lines = $return->items()->orderBy('id')->get();
        if ($lines->isEmpty() || $lines->count() > 200) {
            $this->invalid('source_invalid');
        }
        foreach ($lines as $item) {
            $source = $this->source($order, (int) $item->sales_delivery_item_id);
            if ((int) $item->variant_id !== (int) $source->salesOrderItem->variant_id
                || (int) $item->source_order_id !== (int) $order->id || (int) $item->source_location_id !== (int) $order->location_id
                || (int) $item->source_order_item_id !== (int) $source->sales_order_item_id || $item->unbilled_quantity === null
                || $item->unit_price !== $source->salesOrderItem->unit_price
                || ! in_array($item->disposition, ['restock', 'damaged', 'scrap'], true)
                || ! BigDecimal::of($item->quantity)->isPositive() || ! BigDecimal::of($item->refund_amount)->isZero()) {
                $this->invalid('source_invalid');
            }
        }
    }

    public function assertNoDownstream(SalesReturn $return): void
    {
        $ids = $return->items()->pluck('id');
        if ($return->posted_at !== null || $return->posted_by !== null || $return->status === 'posted'
            || $return->creditNotes()->exists() || $return->posTransaction()->exists()
            || $return->items()->whereHas('creditNoteItems')->exists()
            || $return->posting_payload_hash !== null || $return->posting_evidence_hash !== null || $return->posting_revision !== null
            || $return->costAllocations()->exists() || $return->postedAllocations()->exists()
            || StockMovement::whereIn('reference_type', [(new SalesReturn)->getMorphClass(), (new SalesReturnItem)->getMorphClass()])
                ->where(function ($q) use ($return, $ids): void {
                    $q->where(fn ($q) => $q->where('reference_type', (new SalesReturn)->getMorphClass())->where('reference_id', $return->id))
                        ->orWhere(fn ($q) => $q->where('reference_type', (new SalesReturnItem)->getMorphClass())->whereIn('reference_id', $ids));
                })->exists()) {
            $this->invalid('downstream');
        }
    }

    public function audit(User $actor, SalesReturn $return, string $event): void
    {
        app(AuditLogger::class)->record($event, actor: $actor, metadata: [
            'sales_return_uuid' => $return->uuid, 'sales_order_id' => $return->sales_order_id,
            'location_id' => $return->location_id, 'status' => $return->status, 'revision_number' => $return->revision_number,
            'approval_payload_hash' => $return->approval_payload_hash, 'cancellation_reason' => $return->cancellation_reason,
        ]);
    }

    public function eligibleOrders(?User $actor): Builder
    {
        $query = SalesOrder::whereHas('deliveries', fn ($q) => $q->where('status', 'posted'))
            ->whereHas('location', fn ($q) => $q->where('type', 'branch')->where('is_active', true));
        if (! $this->access->allows($actor, SalesPermissions::RETURNS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('location_id', getPermissionsTeamId());
    }

    public function lineDetails(?User $actor, mixed $orderId, ?array $only = null): array
    {
        if (! is_scalar($orderId) || ! ctype_digit((string) $orderId) || $this->eligibleOrders($actor)->find($orderId) === null) {
            return [];
        }
        $items = SalesDeliveryItem::where('sales_order_id', $orderId)->when($only !== null, fn ($q) => $q->whereIn('id', $only))->whereHas('salesDelivery', fn ($q) => $q->where('status', 'posted'))
            ->with(['salesDelivery', 'salesOrderItem.variant.product', 'salesOrderItem.variant.size', 'salesOrderItem.variant.color'])->orderBy('id')->get();
        $summary = $this->quantities->quantities($items->modelKeys(), lock: false);

        return $items->mapWithKeys(fn ($item) => [$item->id => $summary[$item->id] + [
            'id' => $item->id,
            'label' => $item->salesDelivery->display_reference.' | '.$item->salesOrderItem->variant->displayName().' | #'.$item->id,
        ]])->all();
    }

    private function input(SalesOrder $order, array $data): array
    {
        $data = DocumentSelectors::normalizeInput($data);
        if (array_diff(array_keys($data), ['sales_order_id', 'sales_invoice_id', 'return_date', 'reason', 'notes', 'items']) !== []) {
            $this->invalid('source_invalid');
        }
        $valid = Validator::make($data, [
            'sales_order_id' => 'sometimes|integer|in:'.$order->id, 'sales_invoice_id' => 'nullable|integer|min:1',
            'return_date' => 'required|date_format:Y-m-d', 'reason' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:16000',
            'items' => 'required|array|min:1|max:200',
            'items.*' => 'required|array:id,sales_delivery_item_id,sales_invoice_item_id,variant_id,quantity,disposition,notes,invoice_allocations',
            'items.*.id' => 'nullable|integer|min:1|distinct',
            'items.*.sales_delivery_item_id' => 'required|integer|min:1',
            'items.*.sales_invoice_item_id' => 'nullable|integer|min:1', 'items.*.variant_id' => 'nullable|integer|min:1',
            'items.*.disposition' => 'required|in:restock,damaged,scrap', 'items.*.notes' => 'nullable|string|max:5000',
            'items.*.invoice_allocations' => 'sometimes|array|max:200',
            'items.*.invoice_allocations.*' => 'required|array:sales_invoice_delivery_match_id,quantity',
            'items.*.invoice_allocations.*.sales_invoice_delivery_match_id' => 'required|integer|min:1',
            'items.*.invoice_allocations.*.quantity' => 'required',
        ])->validate();
        $lines = [];
        foreach ($valid['items'] as $item) {
            $quantity = $this->calculator->decimal($item['quantity'] ?? null, 'quantity', 18, 4);
            if (! BigDecimal::of($quantity)->isPositive()) {
                $this->invalid('over_return');
            }
            $line = ['sales_delivery_item_id' => (int) $item['sales_delivery_item_id'],
                'sales_invoice_item_id' => empty($item['sales_invoice_item_id']) ? null : (int) $item['sales_invoice_item_id'],
                'quantity' => $quantity, 'disposition' => $item['disposition'], 'notes' => $item['notes'] ?? null];
            foreach (['id', 'variant_id'] as $field) {
                if (! empty($item[$field])) {
                    $line[$field] = (int) $item[$field];
                }
            }
            if (array_key_exists('invoice_allocations', $item)) {
                $line['invoice_allocations'] = array_values($item['invoice_allocations']);
            }
            $lines[] = $line;
        }

        return [['sales_invoice_id' => empty($valid['sales_invoice_id']) ? null : (int) $valid['sales_invoice_id'],
            'return_date' => $valid['return_date'], 'reason' => $valid['reason'] ?? null, 'notes' => $valid['notes'] ?? null], $lines];
    }

    private function prepare(SalesOrder $order, array $lines, ?int $invoiceId, ?int $excludeReturnId = null): array
    {
        $order->items()->orderBy('id')->lockForUpdate()->get();
        $order->deliveries()->orderBy('id')->lockForUpdate()->get();
        $order->deliveryItems()->orderBy('id')->lockForUpdate()->get();
        foreach ($lines as &$line) {
            $source = $this->source($order, $line['sales_delivery_item_id']);
            if (isset($line['variant_id']) && $line['variant_id'] !== (int) $source->salesOrderItem->variant_id) {
                $this->invalid('source_invalid');
            }
            $line['variant_id'] = (int) $source->salesOrderItem->variant_id;
            $line['source_order_item_id'] = (int) $source->sales_order_item_id;
            $line['unit_price'] = $source->salesOrderItem->unit_price;
        }
        unset($line);
        $this->quantities->assertAvailable($lines, $excludeReturnId);

        return $this->allocations->prepare($order, $lines, $invoiceId, $excludeReturnId);
    }

    private function source(SalesOrder $order, int $id): SalesDeliveryItem
    {
        $source = $order->deliveryItems()->with(['salesDelivery', 'salesOrderItem', 'stockMovement.inventoryCostEntry'])->find($id);
        $delivery = $source?->salesDelivery;
        if ($source === null || $delivery === null || (int) $delivery->location_id !== (int) $order->location_id
            || $delivery->status !== 'posted' || $delivery->posted_at === null || $delivery->posted_by === null
            || $delivery->posting_payload_hash === null || $delivery->posting_evidence_hash === null || $delivery->cancelled_at !== null
            || ! hash_equals($delivery->posting_payload_hash, app(SalesDeliveryPostingService::class)->fingerprint($delivery))
            || $source->stockMovement?->inventoryCostEntry?->entry_type !== 'issue'
            || ! BigDecimal::of($source->quantity)->isPositive()) {
            $this->invalid('source_invalid');
        }
        app(SalesDeliveryPostingService::class)->verifyPostedEvidence($delivery);

        return $source;
    }

    private function saveLines(SalesReturn $return, array $lines): void
    {
        foreach ($lines as $line) {
            $allocations = $line['allocations'];
            $id = $line['id'] ?? null;
            $values = Arr::except($line, ['allocations', 'id']) + ['sales_return_id' => $return->id,
                'source_order_id' => $return->sales_order_id, 'source_location_id' => $return->location_id,
                'refund_amount' => '0.0000', 'updated_at' => now()];
            if ($id === null) {
                $id = DB::table('sales_return_items')->insertGetId($values + ['uuid' => (string) Str::uuid7(), 'created_at' => now()]);
            } else {
                DB::table('sales_return_items')->where('id', $id)->where('sales_return_id', $return->id)->update($values);
            }
            foreach ($allocations as $allocation) {
                DB::table('sales_return_invoice_allocations')->insert($allocation + [
                    'sales_return_id' => $return->id, 'sales_return_item_id' => $id, 'sales_delivery_item_id' => $line['sales_delivery_item_id'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function invalid(string $key): never
    {
        throw ValidationException::withMessages(['record' => __('returns.'.$key)]);
    }
}
