<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SalesInvoiceService
{
    public function __construct(private readonly SalesAccess $access, private readonly PurchaseOrderCalculator $calculator) {}

    public function create(User $actor, array $data, string $submissionId): SalesInvoice
    {
        Validator::make(['submission_id' => $submissionId], ['submission_id' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $data, $submissionId): SalesInvoice {
            ModuleRegistryLock::acquire();
            $data = DocumentSelectors::normalizeInput($data);
            Validator::make($data, ['sales_order_id' => 'required|integer|min:1'])->validate();
            $order = SalesOrder::whereKey($data['sales_order_id'])->lockForUpdate()->firstOrFail();
            app(SalesDocumentLocks::class)->acquire($order);
            $this->authorize($actor, SalesPermissions::INVOICES_CREATE, $order);
            $input = $this->validateInput($order, $data);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = SalesInvoice::where('uuid', strtolower($submissionId))->lockForUpdate()->first();
            if ($existing !== null) {
                abort_unless((int) $existing->created_by === (int) $actor->id && (int) $existing->sales_order_id === (int) $order->id, 403);
                if ($existing->submission_payload_hash !== $hash) {
                    $this->invalid('Invoice submission identity was already used for different details.');
                }

                return $existing->load('items.deliveryMatches');
            }
            $lines = $this->prepare($order, $input['items']);
            $invoice = new SalesInvoice($input['header'] + $this->calculator->totals($lines) + [
                'invoice_number' => app(DocumentNumberService::class)->allocate((int) $order->location_id, 'INV', $submissionId),
                'sales_order_id' => $order->id, 'location_id' => $order->location_id,
                'customer_name' => $order->customer_name, 'customer_phone' => $order->customer_phone,
                'currency' => 'EGP', 'status' => 'draft', 'created_by' => $actor->id,
            ]);
            $invoice->uuid = strtolower($submissionId);
            $invoice->submission_payload_hash = $hash;
            $invoice->save();
            $this->saveLines($invoice, $lines);
            $this->audit($actor, $invoice, 'sales_invoice_created');

            return $invoice->refresh()->load('items.deliveryMatches');
        }, 3);
    }

    public function update(User $actor, SalesInvoice $invoice, array $data, string $revision): SalesInvoice
    {
        return DB::transaction(function () use ($actor, $invoice, $data, $revision): SalesInvoice {
            [$order, $invoice] = $this->lock($actor, $invoice, SalesPermissions::INVOICES_UPDATE);
            $this->draft($invoice);
            $this->checkRevision($invoice, $revision);
            $input = $this->validateInput($order, $data);
            $lines = $this->prepare($order, $input['items']);
            foreach ($invoice->items()->get() as $item) {
                $item->deliveryMatches->each(fn ($match) => $match->delete());
                $item->delete();
            }
            $invoice->fill($input['header'] + $this->calculator->totals($lines))->save();
            $this->saveLines($invoice, $lines);
            $this->audit($actor, $invoice, 'sales_invoice_updated');

            return $invoice->refresh()->load('items.deliveryMatches');
        }, 3);
    }

    public function post(User $actor, SalesInvoice $invoice, string $revision): SalesInvoice
    {
        return DB::transaction(function () use ($actor, $invoice, $revision): SalesInvoice {
            [$order, $invoice] = $this->lock($actor, $invoice, SalesPermissions::INVOICES_POST);
            if ($invoice->status === 'posted') {
                $this->verifyPosted($invoice);
                if (! in_array($revision, [$invoice->posting_revision, $this->revision($invoice)], true)) {
                    $this->invalid('Invoice changed. Reload before continuing.');
                }

                return $invoice;
            }
            $this->draft($invoice);
            $this->checkRevision($invoice, $revision);
            $items = $invoice->items()->with('deliveryMatches')->orderBy('id')->get();
            $input = $items->map(function ($item): array {
                if ($item->deliveryMatches->count() !== 1 || $item->deliveryMatches->first()->matched_quantity !== $item->quantity) {
                    $this->invalid('Invoice matching evidence is inconsistent.');
                }

                return ['sales_delivery_item_id' => $item->deliveryMatches->first()->sales_delivery_item_id, 'quantity' => $item->quantity];
            })->all();
            $lines = $this->prepare($order, $input);
            foreach ($items as $index => $item) {
                foreach (['sales_order_item_id', 'variant_id', 'description', 'line_type', 'quantity', 'unit_price', 'subtotal', 'discount_amount', 'tax_amount', 'line_total'] as $field) {
                    if ((string) $item->{$field} !== (string) $lines[$index][$field]) {
                        $this->invalid('Invoice allocation changed. Save and review the draft before posting.');
                    }
                }
                $item->commercial_snapshot = $lines[$index]['commercial_snapshot'];
                $item->save();
            }
            if ($invoice->currency !== 'EGP' || $invoice->customer_name !== $order->customer_name || $invoice->customer_phone !== $order->customer_phone) {
                $this->invalid('Invoice customer and currency must match its sales order.');
            }
            foreach ($this->calculator->totals($lines) as $field => $value) {
                if ($invoice->{$field} !== $value) {
                    $this->invalid('Invoice totals do not reconcile.');
                }
            }
            $snapshot = ['company' => config('sales-invoices.company'),
                'branch' => Arr::only($order->location->toArray(), ['name', 'address', 'phone']),
                'order_number' => $order->order_number, 'allocation' => 'proportional-half-up-4-final-remainder'];
            DB::table('sales_invoices')->where('id', $invoice->id)->update([
                'status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id,
                'posting_revision' => $revision, 'document_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
            $invoice->refresh();
            DB::table('sales_invoices')->where('id', $invoice->id)->update(['posting_payload_hash' => $this->fingerprint($invoice)]);
            $this->audit($actor, $invoice, 'sales_invoice_posted');

            return $invoice->refresh()->load('items.deliveryMatches');
        }, 3);
    }

    public function cancel(User $actor, SalesInvoice $invoice, string $revision): SalesInvoice
    {
        return DB::transaction(function () use ($actor, $invoice, $revision): SalesInvoice {
            [, $invoice] = $this->lock($actor, $invoice, SalesPermissions::INVOICES_CANCEL);
            if ($invoice->status === 'cancelled' && $invoice->cancelled_at !== null && $invoice->posted_at === null) {
                return $invoice;
            }
            $this->draft($invoice);
            $this->checkRevision($invoice, $revision);
            DB::table('sales_invoices')->where('id', $invoice->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'updated_at' => now(),
            ]);
            $this->audit($actor, $invoice->refresh(), 'sales_invoice_cancelled');

            return $invoice;
        }, 3);
    }

    public function revision(SalesInvoice $invoice): string
    {
        return $this->fingerprint($invoice);
    }

    public function verifyPosted(SalesInvoice $invoice): void
    {
        if ($invoice->status !== 'posted' || $invoice->posted_at === null || $invoice->posted_by === null
            || $invoice->posting_payload_hash === null || $invoice->document_snapshot === null
            || ! hash_equals($invoice->posting_payload_hash, $this->fingerprint($invoice))) {
            $this->invalid('Invoice posting evidence is inconsistent.');
        }
    }

    public function eligibleOrders(?User $actor): Builder
    {
        $query = SalesOrder::whereIn('status', ['confirmed', 'partially_fulfilled', 'fulfilled', 'closed'])
            ->whereHas('deliveries', fn ($query) => $query->where('status', 'posted'))
            ->whereHas('location', fn ($query) => $query->where('type', 'branch')->where('is_active', true));
        if (! $this->access->allows($actor, SalesPermissions::INVOICES_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $actor->isGlobalSuperAdmin() ? $query : $query->where('location_id', getPermissionsTeamId());
    }

    public function lineDetails(?User $actor, mixed $orderId, ?array $only = null): array
    {
        if (! is_scalar($orderId) || ! ctype_digit((string) $orderId) || $this->eligibleOrders($actor)->find($orderId) === null) {
            return [];
        }

        return SalesDeliveryItem::where('sales_order_id', $orderId)->when($only !== null, fn ($q) => $q->whereIn('id', $only))->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted'))
            ->with(['salesDelivery', 'salesOrderItem.variant.product', 'salesOrderItem.variant.size', 'salesOrderItem.variant.color'])->orderBy('id')->get()->mapWithKeys(function ($item): array {
                $used = $this->postedQuantity((int) $item->id);
                $remaining = BigDecimal::of($item->quantity)->minus($used)->minus(app(SalesReturnInvoiceReconciliation::class)->unbilled((int) $item->id));
                if ($remaining->isNegative()) {
                    $this->invalid('Historical invoice quantities exceed delivered quantities.');
                }

                return [$item->id => ['label' => $item->salesDelivery->display_reference.' | '.$item->salesOrderItem->variant?->displayName().' | #'.$item->id,
                    'delivered' => $item->quantity, 'invoiced' => $used, 'remaining' => (string) $remaining]];
            })->all();
    }

    private function validateInput(SalesOrder $order, array $data): array
    {
        $data = DocumentSelectors::normalizeInput($data);
        if (array_diff(array_keys($data), ['sales_order_id', 'invoice_date', 'due_date', 'notes', 'items']) !== []) {
            $this->invalid('Invoice amounts and identity are controlled by Sales workflows.');
        }
        $data = Validator::make($data, [
            'sales_order_id' => ['sometimes', 'integer', 'in:'.$order->id],
            'invoice_date' => 'required|date_format:Y-m-d', 'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:invoice_date',
            'notes' => 'nullable|string|max:16000', 'items' => 'required|array|min:1|max:200',
            'items.*' => 'required|array:sales_delivery_item_id,quantity',
            'items.*.sales_delivery_item_id' => 'required|integer|min:1|distinct',
        ])->validate();
        $lines = [];
        foreach ($data['items'] as $index => $item) {
            $quantity = $this->calculator->decimal($item['quantity'] ?? null, 'items.'.$index.'.quantity', 18, 4);
            if (! BigDecimal::of($quantity)->isPositive()) {
                $this->invalid('Invoice quantities must be positive.');
            }
            $lines[] = ['sales_delivery_item_id' => (int) $item['sales_delivery_item_id'], 'quantity' => $quantity];
        }

        return ['header' => ['invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null], 'items' => $lines];
    }

    /** The order lock serializes competing invoice postings for all its deliveries. */
    private function prepare(SalesOrder $order, array $input): array
    {
        if ($input === [] || count($input) > 200 || count(array_unique(array_column($input, 'sales_delivery_item_id'))) !== count($input)) {
            $this->invalid('Select between one and 200 distinct posted delivery lines.');
        }
        $this->eligible($order);
        $orderItems = $order->items()->with(['variant.product', 'variant.size', 'variant.color'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deliveries = $order->deliveries()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $deliveryItems = SalesDeliveryItem::whereIn('sales_delivery_id', $deliveries->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $running = [];
        $lines = [];
        foreach ($input as $index => $inputLine) {
            $deliveryItem = $deliveryItems->get($inputLine['sales_delivery_item_id']);
            $delivery = $deliveryItem === null ? null : $deliveries->get($deliveryItem->sales_delivery_id);
            $source = $deliveryItem === null ? null : $orderItems->get($deliveryItem->sales_order_item_id);
            if ($delivery === null || $source === null || $source->variant === null || $delivery->status !== 'posted'
                || $delivery->posted_at === null || $delivery->posted_by === null || $delivery->cancelled_at !== null
                || $delivery->posting_payload_hash === null || $delivery->posting_evidence_hash === null
                || ! hash_equals($delivery->posting_payload_hash, app(SalesDeliveryPostingService::class)->fingerprint($delivery))
                || (int) $delivery->location_id !== (int) $order->location_id || (int) $deliveryItem->sales_order_id !== (int) $order->id) {
                $this->invalid('Choose posted delivery lines belonging to the same order and branch.');
            }
            $quantity = $this->calculator->decimal($inputLine['quantity'], 'items.'.$index.'.quantity', 18, 4);
            if (! BigDecimal::of($quantity)->isPositive()
                || BigDecimal::of($quantity)->plus($this->postedQuantity((int) $deliveryItem->id))
                    ->plus(app(SalesReturnInvoiceReconciliation::class)->unbilled((int) $deliveryItem->id))->isGreaterThan($deliveryItem->quantity)) {
                $this->invalid('Invoice quantity exceeds delivered but uninvoiced quantity.');
            }
            $running[$source->id] ??= SalesInvoiceItem::where('sales_order_item_id', $source->id)
                ->whereHas('salesInvoice', fn ($query) => $query->where('status', 'posted'))
                ->with(['deliveryMatches.salesDeliveryItem', 'salesInvoice'])->get()
                ->reduce(function (array $sum, $item) use ($order): array {
                    $matched = BigDecimal::zero();
                    foreach ($item->deliveryMatches as $match) {
                        if ((int) $match->salesDeliveryItem->sales_order_item_id !== (int) $item->sales_order_item_id
                            || (int) $match->salesDeliveryItem->sales_order_id !== (int) $order->id
                            || (int) $item->salesInvoice->sales_order_id !== (int) $order->id
                            || (int) $item->salesInvoice->location_id !== (int) $order->location_id) {
                            $this->invalid('Invoice matching evidence is inconsistent.');
                        }
                        $matched = $matched->plus($match->matched_quantity);
                    }
                    if (! $matched->isEqualTo($item->quantity)) {
                        $this->invalid('Invoice matching evidence is inconsistent.');
                    }
                    foreach (array_keys($sum) as $field) {
                        $sum[$field] = (string) BigDecimal::of($sum[$field])->plus($field === 'quantity' ? $matched : $item->{$field});
                    }

                    return $sum;
                }, ['quantity' => '0.0000', 'subtotal' => '0.0000', 'discount_amount' => '0.0000', 'tax_amount' => '0.0000']);
            $previous = $running[$source->id];
            $newQuantity = BigDecimal::of($previous['quantity'])->plus($quantity);
            if ($newQuantity->isGreaterThan($source->quantity)) {
                $this->invalid('Invoice quantity exceeds the agreed order quantity.');
            }
            $line = ['sales_order_item_id' => $source->id, 'variant_id' => $source->variant_id, 'line_type' => 'product',
                'description' => $source->description, 'quantity' => $quantity, 'unit_price' => $source->unit_price,
                'sales_delivery_item_id' => $deliveryItem->id];
            foreach (['subtotal', 'discount_amount', 'tax_amount'] as $field) {
                $remainingAmount = BigDecimal::of($source->{$field})->minus($previous[$field]);
                $proportional = BigDecimal::of($source->{$field})->multipliedBy($quantity)->dividedBy($source->quantity, 4, RoundingMode::HalfUp);
                $allocated = $newQuantity->isEqualTo($source->quantity) ? $remainingAmount : BigDecimal::min($remainingAmount, $proportional);
                if ($allocated->isNegative()) {
                    $this->invalid('Historical invoice allocations conflict with agreed order amounts.');
                }
                $line[$field] = $this->calculator->decimal((string) $allocated, $field);
                $running[$source->id][$field] = (string) BigDecimal::of($previous[$field])->plus($allocated);
            }
            $running[$source->id]['quantity'] = (string) $newQuantity;
            if (BigDecimal::of($line['discount_amount'])->isGreaterThan($line['subtotal'])) {
                $this->invalid('Rounded discount exceeds the invoice line subtotal.');
            }
            $line['line_total'] = $this->calculator->decimal((string) BigDecimal::of($line['subtotal'])->minus($line['discount_amount'])->plus($line['tax_amount']), 'line_total');
            $line['commercial_snapshot'] = ['product_name' => $source->variant->product?->name, 'sku' => $source->variant->sku,
                'size' => $source->variant->size?->name, 'color' => $source->variant->color?->name,
                'delivery_number' => $delivery->delivery_number, 'delivered_quantity' => $deliveryItem->quantity,
                'order_quantity' => $source->quantity, 'order_subtotal' => $source->subtotal,
                'order_discount' => $source->discount_amount, 'order_tax' => $source->tax_amount,
                'allocation' => 'proportional-half-up-4-final-remainder'];
            if ($source->commercial_snapshot !== null) {
                $line['commercial_snapshot']['order_pricing'] = $source->commercial_snapshot;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    private function postedQuantity(int $deliveryItemId): string
    {
        return (string) BigDecimal::of(DB::table('sales_invoice_delivery_matches as matches')
            ->join('sales_invoice_items as items', 'items.id', '=', 'matches.sales_invoice_item_id')
            ->join('sales_invoices as invoices', 'invoices.id', '=', 'items.sales_invoice_id')
            ->where('invoices.status', 'posted')->where('matches.sales_delivery_item_id', $deliveryItemId)->sum('matches.matched_quantity'))->toScale(4);
    }

    private function saveLines(SalesInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $item = $invoice->items()->create(Arr::except($line, 'sales_delivery_item_id'));
            $item->deliveryMatches()->create(['sales_delivery_item_id' => $line['sales_delivery_item_id'], 'matched_quantity' => $line['quantity']]);
        }
    }

    private function lock(User $actor, SalesInvoice $invoice, string $permission): array
    {
        ModuleRegistryLock::acquire();
        $order = SalesOrder::whereKey(SalesInvoice::whereKey($invoice->id)->value('sales_order_id'))->lockForUpdate()->firstOrFail();
        app(SalesDocumentLocks::class)->acquire($order);
        $this->authorize($actor, $permission, $order);
        $invoice = $order->invoices()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        if ((int) $invoice->location_id !== (int) $order->location_id) {
            $this->invalid('Invoice branch must match its order.');
        }
        $invoice->items()->orderBy('id')->lockForUpdate()->get();

        return [$order, $invoice];
    }

    private function authorize(User $actor, string $permission, SalesOrder $order): void
    {
        $this->access->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $order->location_id);
        $this->access->authorize($actor, $permission, (int) $order->location_id);
        abort_unless($order->location()->where('type', 'branch')->where('is_active', true)->sharedLock()->exists(), 403);
    }

    private function eligible(SalesOrder $order): void
    {
        if (! in_array($order->status, ['confirmed', 'partially_fulfilled', 'fulfilled', 'closed'], true)
            || $order->confirmed_at === null || $order->confirmation_payload_hash === null || $order->cancelled_at !== null
            || ! hash_equals($order->confirmation_payload_hash, app(SalesOrderConfirmationService::class)->fingerprint($order))) {
            $this->invalid('Choose a sales order with valid confirmation evidence and posted deliveries.');
        }
    }

    private function draft(SalesInvoice $invoice): void
    {
        if ($invoice->status !== 'draft' || $invoice->posted_at !== null || $invoice->posted_by !== null || $invoice->cancelled_at !== null
            || $invoice->posting_payload_hash !== null || $invoice->posting_revision !== null || $invoice->cancelled_by !== null) {
            $this->invalid('Only draft invoices can be changed.');
        }
    }

    private function checkRevision(SalesInvoice $invoice, string $revision): void
    {
        if (! hash_equals($this->revision($invoice), $revision)) {
            $this->invalid('Invoice changed. Reload before continuing.');
        }
    }

    private function fingerprint(SalesInvoice $invoice): string
    {
        $header = Arr::except($invoice->getAttributes(), ['posting_payload_hash']);
        ksort($header);
        $items = $invoice->items()->with('deliveryMatches')->orderBy('id')->get()->map(fn ($item): array => [
            $item->getAttributes(), $item->deliveryMatches->sortBy('id')->map->getAttributes()->values()->all(),
        ])->all();

        return hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
    }

    private function audit(User $actor, SalesInvoice $invoice, string $event): void
    {
        app(AuditLogger::class)->record($event, actor: $actor, metadata: [
            'sales_invoice_uuid' => $invoice->uuid, 'sales_order_id' => $invoice->sales_order_id,
            'location_id' => $invoice->location_id, 'status' => $invoice->status, 'total_amount' => $invoice->total_amount,
        ]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['record' => __($message)]);
    }
}
