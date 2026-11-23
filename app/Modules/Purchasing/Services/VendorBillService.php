<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillReceiptMatch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorBillService
{
    /** @var array<int, true> Internal posting boundary; query-builder writes remain trusted escape hatches. */
    private static array $validatedPosting = [];

    public static function isPostingValidated(int $billId): bool
    {
        return isset(self::$validatedPosting[$billId]);
    }

    public function __construct(private readonly PurchasingAccess $access, private readonly PurchaseOrderCalculator $calculator) {}

    /** Draft matches are proposals. Only posted bills consume receipt quantities. */
    public function create(User $actor, array $data): VendorBill
    {
        return $this->uniqueInvoice(fn (): VendorBill => DB::transaction(function () use ($actor, $data): VendorBill {
            ModuleRegistryLock::acquire();
            $data = DocumentSelectors::normalizeInput($data);
            Validator::make($data, ['purchase_order_id' => ['required', 'integer']])->validate();
            $order = PurchaseOrder::whereKey($data['purchase_order_id'])->lockForUpdate()->firstOrFail();
            $this->authorize($actor, PurchasingPermissions::BILLS_CREATE, $order);
            $header = $this->header($data, $order);
            $lines = $this->lines($order, $data['items'] ?? []);
            $totals = $this->calculator->totals($lines);
            $header += app(PurchaseSettlementTerms::class)->validate($data, $totals['total_amount']);
            $bill = new VendorBill($header + $totals + ['status' => 'draft', 'created_by' => $actor->id]);
            $bill->uuid = (string) Str::uuid7();
            app(DocumentNumberService::class)->allocate((int) $order->location_id, 'BILL', $bill->uuid);
            $bill->save();
            $this->saveLines($bill, $lines);
            $this->audit($actor, $bill, 'vendor_bill_draft_created');

            return $bill->refresh()->load('items.receiptMatches');
        }, 3));
    }

    public function update(User $actor, VendorBill $bill, array $data, string $revision): VendorBill
    {
        return $this->uniqueInvoice(fn (): VendorBill => DB::transaction(function () use ($actor, $bill, $data, $revision): VendorBill {
            [$order, $bill] = $this->lock($bill);
            $this->authorize($actor, PurchasingPermissions::BILLS_UPDATE, $order);
            $this->draft($bill);
            $this->checkRevision($bill, $revision);
            $header = $this->header($data, $order);
            $lines = $this->lines($order, $data['items'] ?? []);
            $totals = $this->calculator->totals($lines);
            $header += app(PurchaseSettlementTerms::class)->validate($data, $totals['total_amount']);
            foreach ($bill->items()->with('receiptMatches')->orderBy('id')->get() as $item) {
                foreach ($item->receiptMatches as $match) {
                    $match->delete();
                }
                $item->delete();
            }
            $bill->fill($header + $totals)->save();
            $this->saveLines($bill, $lines);
            $this->audit($actor, $bill, 'vendor_bill_draft_updated');

            return $bill->refresh()->load('items.receiptMatches');
        }, 3));
    }

    public function post(User $actor, VendorBill $bill, string $revision): VendorBill
    {
        return DB::transaction(function () use ($actor, $bill, $revision): VendorBill {
            [$order, $bill] = $this->lock($bill);
            $this->authorize($actor, PurchasingPermissions::BILLS_POST, $order);
            if ($bill->status === 'posted') {
                return $bill->load('items.receiptMatches');
            }
            $this->draft($bill);
            $this->checkRevision($bill, $revision);
            $this->header($bill->only(['supplier_id', 'purchase_order_id', 'bill_number', 'bill_date', 'due_date', 'notes']), $order);
            app(PurchaseSettlementTerms::class)->validate($bill->only(['settlement_terms', 'planned_immediate_amount', 'bill_date', 'due_date']), $bill->total_amount);
            $items = $bill->items()->orderBy('id')->lockForUpdate()
                ->with(['receiptMatches' => fn (HasMany $matches): HasMany => $matches
                    ->orderBy('purchase_receipt_item_id')->lockForUpdate()])
                ->get();
            $data = $items->map(fn ($item): array => $item->only(['variant_id', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_amount'])
                + ['allocations' => $item->receiptMatches
                    ->map(fn ($match): array => ['purchase_receipt_item_id' => $match->purchase_receipt_item_id, 'quantity' => $match->matched_quantity])->all()])->all();
            $lines = $this->lines($order, $data);
            foreach ($this->calculator->totals($lines) as $field => $amount) {
                if (! BigDecimal::of($bill->{$field})->isEqualTo($amount)) {
                    $this->invalid('totals', 'Bill totals changed. Save the draft again before posting.');
                }
            }
            foreach ($items as $index => $item) {
                if ($this->normalizeSnapshot($item->commercial_snapshot) !== $this->normalizeSnapshot($lines[$index]['commercial_snapshot'] ?? null)) {
                    $this->invalid('totals', 'Bill pricing evidence changed. Save the draft again before posting.');
                }
                foreach (['subtotal', 'line_total'] as $field) {
                    if (! BigDecimal::of($item->{$field})->isEqualTo($lines[$index][$field])) {
                        $this->invalid('totals', 'Bill totals changed. Save the draft again before posting.');
                    }
                }
            }
            self::$validatedPosting[$bill->id] = true;
            try {
                $bill->fill(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            } finally {
                unset(self::$validatedPosting[$bill->id]);
            }
            $this->audit($actor, $bill, 'vendor_bill_posted');

            return $bill->refresh()->load('items.receiptMatches');
        }, 3);
    }

    public function cancel(User $actor, VendorBill $bill, string $revision): VendorBill
    {
        return DB::transaction(function () use ($actor, $bill, $revision): VendorBill {
            [$order, $bill] = $this->lock($bill);
            $this->authorize($actor, PurchasingPermissions::BILLS_CANCEL, $order);
            if ($bill->status === 'cancelled') {
                return $bill;
            }
            $this->draft($bill);
            $this->checkRevision($bill, $revision);
            $bill->status = 'cancelled';
            $bill->save();
            $this->audit($actor, $bill, 'vendor_bill_draft_cancelled');

            return $bill->refresh();
        }, 3);
    }

    public function revision(VendorBill $bill): string
    {
        return hash('sha256', json_encode([$bill->getAttributes(), $bill->items()->with(['receiptMatches' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get()->map(fn ($item): array => [
            $item->getAttributes(), $item->receiptMatches->map->getAttributes()->all(),
        ])->all()], JSON_THROW_ON_ERROR));
    }

    public function prefill(User $actor, PurchaseOrder $order, ?PurchaseReceipt $receipt = null): array
    {
        RequestAuthorizationFacts::duringRead(function () use ($actor, $order): void {
            $this->authorize($actor, PurchasingPermissions::BILLS_CREATE, $order);
            $this->access->authorize($actor, PurchasingPermissions::ORDERS_VIEW, (int) $order->location_id);
            $this->access->authorize($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $order->location_id);
        });
        if ($receipt !== null && ((int) $receipt->purchase_order_id !== (int) $order->id || $receipt->status !== 'posted'
            || (int) $receipt->location_id !== (int) $order->location_id)) {
            $this->invalid('receipt', 'Select a posted receipt belonging to this purchase order and location.');
        }
        $items = [];
        $lines = $this->receiptItemsQuery($order)->when($receipt !== null, fn ($query) => $query->where('purchase_receipt_id', $receipt->id))
            ->select(['id', 'purchase_order_id', 'purchase_receipt_id', 'purchase_order_item_id', 'quantity', 'unit_cost'])->get();
        $billedByOrderLine = $this->billedQuantitiesByOrderLine($order);
        $billedByReceiptItem = $lines->isEmpty() ? collect() : VendorBillReceiptMatch::whereIn('purchase_receipt_item_id', $lines->modelKeys())
            ->whereHas('vendorBillItem.vendorBill', fn ($query) => $query->where('status', 'posted'))
            ->selectRaw('purchase_receipt_item_id, SUM(matched_quantity) as billed_quantity')
            ->groupBy('purchase_receipt_item_id')->pluck('billed_quantity', 'purchase_receipt_item_id');
        $remainingByReceiptItem = $lines->mapWithKeys(fn (PurchaseReceiptItem $line): array => [
            $line->id => (string) BigDecimal::of($line->quantity)->minus($billedByReceiptItem->get($line->id, '0'))->toScale(4)]);
        $lines = $lines->filter(fn (PurchaseReceiptItem $line): bool => BigDecimal::of($remainingByReceiptItem[$line->id])->isPositive());
        $lines->load(['purchaseOrderItem:id,variant_id,quantity,discount_amount,tax_amount',
            'purchaseOrderItem.variant:id,product_id,unallocated_product_id,color_id,size_id',
            'purchaseOrderItem.variant.product:id,name', 'purchaseOrderItem.variant.color:id,name',
            'purchaseOrderItem.variant.size:id,name']);
        $currentBillQuantities = [];
        foreach ($lines as $line) {
            $remaining = $remainingByReceiptItem[$line->id];
            $poLine = $line->purchaseOrderItem;
            $item = ['variant_id' => $poLine->variant_id, 'description' => $poLine->variant->displayName(),
                'quantity' => $remaining, 'unit_price' => $line->unit_cost,
                'allocations' => [['purchase_receipt_item_id' => $line->id, 'quantity' => $remaining]]];
            if ($poLine->commercial_snapshot !== null) {
                $alreadyBilled = (string) BigDecimal::of($billedByOrderLine[$poLine->id] ?? '0')
                    ->plus($currentBillQuantities[$poLine->id] ?? '0')->toScale(4);
                $pricing = app(PurchaseLinePricing::class)->allocate($poLine->commercial_snapshot, $remaining,
                    $line->unit_cost, $poLine->quantity, $alreadyBilled);
                $item['discount_amount'] = $pricing['discount_amount'];
                $item['tax_amount'] = $pricing['tax_amount'];
                $currentBillQuantities[$poLine->id] = (string) BigDecimal::of($currentBillQuantities[$poLine->id] ?? '0')
                    ->plus($remaining)->toScale(4);
            } else {
                $item['discount_amount'] = $this->prorate($poLine->discount_amount, $remaining, $poLine->quantity);
                $item['tax_amount'] = $this->prorate($poLine->tax_amount, $remaining, $poLine->quantity);
            }
            $items[] = $item;
        }
        if ($items === []) {
            $this->invalid('items', 'There are no posted receipt quantities available to bill.');
        }

        return ['supplier_id' => $order->supplier_id, 'purchase_order_id' => $order->id, 'bill_date' => now()->toDateString(), 'items' => $items];
    }

    public function remaining(PurchaseReceiptItem $line): string
    {
        $billed = BigDecimal::zero();
        foreach (VendorBillReceiptMatch::where('purchase_receipt_item_id', $line->id)
            ->whereHas('vendorBillItem.vendorBill', fn ($query) => $query->where('status', 'posted'))->get() as $match) {
            $billed = $billed->plus($match->matched_quantity);
        }

        return (string) BigDecimal::of($line->quantity)->minus($billed)->toScale(4);
    }

    public function receiptLines(PurchaseOrder $order): Collection
    {
        return $this->receiptItemsQuery($order)->with(['purchaseReceipt', 'purchaseOrderItem.variant.product',
            'purchaseOrderItem.variant.color', 'purchaseOrderItem.variant.size'])->get();
    }

    private function receiptItemsQuery(PurchaseOrder $order): HasMany
    {
        return $order->receiptItems()->whereHas('purchaseReceipt', fn ($query) => $query->where('status', 'posted')
            ->where('location_id', $order->location_id))->orderBy('id');
    }

    private function header(array $data, PurchaseOrder $order): array
    {
        $data = DocumentSelectors::normalizeInput($data);
        if (array_diff(array_keys($data), ['supplier_id', 'purchase_order_id', 'bill_number', 'bill_date', 'due_date', 'notes', 'items', 'settlement_terms', 'planned_immediate_amount']) !== []) {
            $this->invalid('bill', 'Bill status, totals and audit fields are controlled by bill operations.');
        }
        Validator::make($data, ['supplier_id' => ['required', 'integer'], 'purchase_order_id' => ['required', 'integer'],
            'bill_number' => ['required', 'string', 'max:255'], 'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'], 'notes' => ['nullable', 'string', 'max:16000']])->validate();
        if ((int) $data['purchase_order_id'] !== (int) $order->id || (int) $data['supplier_id'] !== (int) $order->supplier_id
            || ! in_array($order->status, ['confirmed', 'partially_received', 'received'], true)) {
            $this->invalid('purchase_order_id', 'Bill supplier and purchase order must match a confirmed receiving document.');
        }
        $data['bill_number'] = trim($data['bill_number']);
        if ($data['bill_number'] === '') {
            $this->invalid('bill_number', 'Enter the supplier invoice number.');
        }

        return Arr::only($data, ['supplier_id', 'purchase_order_id', 'bill_number', 'bill_date', 'due_date', 'notes']);
    }

    /** Parent PO and receipt locks serialize allocation commits for an order. */
    private function lines(PurchaseOrder $order, mixed $data): array
    {
        Validator::make(['items' => $data], ['items' => ['required', 'array', 'min:1', 'max:200'], 'items.*' => ['array'],
            'items.*.variant_id' => ['required', 'integer'], 'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.allocations' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.allocations.*' => ['array'], 'items.*.allocations.*.purchase_receipt_item_id' => ['required', 'integer']])->validate();
        $order->receipts()->orderBy('id')->lockForUpdate()->get();
        $receipts = $order->receiptItems()->with(['purchaseReceipt', 'purchaseOrderItem'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $billedByOrderLine = $this->billedQuantitiesByOrderLine($order);
        $aggregate = [];
        $lines = [];
        $currentBillQuantities = [];
        foreach (array_values($data) as $index => $line) {
            $prefix = 'items.'.$index.'.';
            if (array_diff(array_keys($line), ['variant_id', 'description', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'allocations']) !== []) {
                $this->invalid($prefix.'quantity', 'Bill status, totals and audit fields are controlled by bill operations.');
            }
            $base = $this->calculator->line(['quantity' => $line['quantity'] ?? null, 'unit_price' => $line['unit_price'] ?? null], $prefix);
            $sum = BigDecimal::zero();
            $seen = [];
            $poLineId = null;
            $allocations = [];
            foreach ($line['allocations'] as $allocation) {
                if (array_diff(array_keys($allocation), ['purchase_receipt_item_id', 'quantity']) !== []) {
                    $this->invalid($prefix.'allocations', 'Invalid receipt allocation.');
                }
                $receipt = $receipts->get($allocation['purchase_receipt_item_id']);
                if ($receipt === null || $receipt->purchaseReceipt->status !== 'posted'
                    || (int) $receipt->purchaseReceipt->location_id !== (int) $order->location_id
                    || (int) $receipt->purchase_order_id !== (int) $order->id
                    || (int) $receipt->purchaseOrderItem->purchase_order_id !== (int) $order->id
                    || (int) $receipt->purchaseOrderItem->variant_id !== (int) $line['variant_id']
                    || ($poLineId !== null && $poLineId !== (int) $receipt->purchase_order_item_id)) {
                    $this->invalid($prefix.'allocations', 'Receipt, purchase order, variant and location must match.');
                }
                $poLineId = (int) $receipt->purchase_order_item_id;
                if (isset($seen[$receipt->id])) {
                    $this->invalid($prefix.'allocations', 'Duplicate receipt allocation within a bill line.');
                }
                $seen[$receipt->id] = true;
                $quantity = $this->calculator->decimal($allocation['quantity'] ?? null, $prefix.'allocations', 18, 4);
                if (! BigDecimal::of($quantity)->isPositive()) {
                    $this->invalid($prefix.'allocations', 'Matched quantity must be positive.');
                }
                $sum = $sum->plus($quantity);
                $aggregate[$receipt->id] = (string) BigDecimal::of($aggregate[$receipt->id] ?? '0')->plus($quantity);
                $allocations[] = ['purchase_receipt_item_id' => $receipt->id, 'matched_quantity' => $quantity];
            }
            if (! $sum->isEqualTo($base['quantity'])) {
                $this->invalid($prefix.'quantity', 'Matched quantities must equal the bill line quantity.');
            }
            $source = $receipts->get(array_key_first($seen))->purchaseOrderItem;
            if ($source->commercial_snapshot !== null) {
                $alreadyBilled = (string) BigDecimal::of($billedByOrderLine[$source->id] ?? '0')
                    ->plus($currentBillQuantities[$source->id] ?? '0')->toScale(4);
                $pricing = app(PurchaseLinePricing::class)->allocate($source->commercial_snapshot,
                    $base['quantity'], $base['unit_price'], $source->quantity, $alreadyBilled, $prefix);
                $calculated = $pricing;
                $calculated['commercial_snapshot'] = ['version' => 1,
                    'purchase_order_pricing' => $source->commercial_snapshot,
                    'purchase_order_item_id' => $source->id,
                    'calculation' => $pricing['commercial_snapshot'],
                    'inventory_revaluation' => false];
                $currentBillQuantities[$source->id] = (string) BigDecimal::of($currentBillQuantities[$source->id] ?? '0')
                    ->plus($calculated['quantity'])->toScale(4);
            } else {
                $calculated = $this->calculator->line($line, $prefix);
            }
            $lines[] = $calculated + Arr::only($line, ['variant_id', 'description']) + ['allocations' => $allocations];
        }
        $billedByReceipt = $aggregate === [] ? collect() : VendorBillReceiptMatch::query()
            ->whereIn('purchase_receipt_item_id', array_keys($aggregate))
            ->whereHas('vendorBillItem.vendorBill', fn ($query) => $query->where('status', 'posted'))
            ->selectRaw('purchase_receipt_item_id, SUM(matched_quantity) as billed_quantity')
            ->groupBy('purchase_receipt_item_id')->pluck('billed_quantity', 'purchase_receipt_item_id');
        foreach ($aggregate as $receiptId => $quantity) {
            $remaining = BigDecimal::of($receipts[$receiptId]->quantity)
                ->minus($billedByReceipt->get($receiptId, '0'))->toScale(4);
            if (BigDecimal::of($quantity)->isGreaterThan($remaining)) {
                $this->invalid('items', 'Bill quantity exceeds the remaining posted receipt quantity.');
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function billedQuantitiesByOrderLine(PurchaseOrder $order): array
    {
        return DB::table('vendor_bill_receipt_matches as matches')
            ->join('purchase_receipt_items as receipt_items', 'receipt_items.id', '=', 'matches.purchase_receipt_item_id')
            ->join('vendor_bill_items as bill_items', 'bill_items.id', '=', 'matches.vendor_bill_item_id')
            ->join('vendor_bills as bills', 'bills.id', '=', 'bill_items.vendor_bill_id')
            ->where('receipt_items.purchase_order_id', $order->id)->where('bills.status', 'posted')
            ->groupBy('receipt_items.purchase_order_item_id')
            ->selectRaw('receipt_items.purchase_order_item_id, SUM(matches.matched_quantity) AS billed_quantity')
            ->pluck('billed_quantity', 'receipt_items.purchase_order_item_id')->all();
    }

    private function saveLines(VendorBill $bill, array $lines): void
    {
        foreach ($lines as $line) {
            $item = $bill->items()->create(Arr::except($line, ['allocations']));
            foreach ($line['allocations'] as $allocation) {
                $item->receiptMatches()->create($allocation);
            }
        }
    }

    private function lock(VendorBill $bill): array
    {
        ModuleRegistryLock::acquire();
        $orderId = VendorBill::whereKey($bill->id)->value('purchase_order_id');
        $order = PurchaseOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $bill = VendorBill::whereKey($bill->id)->where('purchase_order_id', $order->id)->lockForUpdate()->firstOrFail();

        return [$order, $bill];
    }

    private function authorize(User $actor, string $permission, PurchaseOrder $order): void
    {
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->access->authorize($actor, PurchasingPermissions::BILLS_VIEW, (int) $order->location_id);
        $this->access->authorize($actor, $permission, (int) $order->location_id);
        if (in_array($permission, [PurchasingPermissions::BILLS_CREATE, PurchasingPermissions::BILLS_UPDATE], true)) {
            $this->access->authorize($actor, PurchasingPermissions::ORDERS_VIEW, (int) $order->location_id);
            $this->access->authorize($actor, PurchasingPermissions::RECEIPTS_VIEW, (int) $order->location_id);
        }
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

    private function draft(VendorBill $bill): void
    {
        if ($bill->status !== 'draft') {
            $this->invalid('status', 'Only draft bills can be edited, posted or cancelled. Posted corrections require a future credit note workflow.');
        }
    }

    private function checkRevision(VendorBill $bill, string $revision): void
    {
        if (! hash_equals($this->revision($bill), $revision)) {
            $this->invalid('revision', 'This bill changed. Reload before continuing.');
        }
    }

    private function prorate(string $amount, string $quantity, string $ordered): string
    {
        return (string) BigDecimal::of($amount)->multipliedBy($quantity)->dividedBy($ordered, 4, RoundingMode::HalfUp);
    }

    private function audit(User $actor, VendorBill $bill, string $event): void
    {
        app(AuditLogger::class)->record($event, actor: $actor, metadata: [
            'vendor_bill_id' => $bill->id, 'vendor_bill_uuid' => $bill->uuid, 'purchase_order_id' => $bill->purchase_order_id,
            'supplier_id' => $bill->supplier_id, 'location_id' => $bill->purchaseOrder->location_id,
            'status' => $bill->status, 'total_amount' => $bill->total_amount,
        ]);
    }

    private function uniqueInvoice(callable $operation): VendorBill
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'uq_vendor_bills_supplier_number')) {
                throw $exception;
            }
            $this->invalid('bill_number', 'This supplier invoice number already exists.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
