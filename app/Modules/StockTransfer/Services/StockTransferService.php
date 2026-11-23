<?php

namespace App\Modules\StockTransfer\Services;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\InventoryCosting\Services\StockTransferValuationService;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferService
{
    public function createDraft(User $actor, array $data): StockTransfer
    {
        $locationIds = $this->validateLocationIds($data);

        return DB::transaction(function () use ($actor, $data, $locationIds): StockTransfer {
            ModuleRegistryLock::acquire();
            $this->authorizeLocations($actor, StockTransferPermissions::MANAGE, $locationIds['from_location_id'], $locationIds['to_location_id']);
            $source = Location::query()->whereKey($locationIds['from_location_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $destination = Location::query()->whereKey($locationIds['to_location_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->validateLocations($source, $destination);
            $data = $this->validateDraft($data);
            $this->validateAvailability((int) $source->id, $data['items']);
            $uuid = (string) Str::uuid7();
            $number = app(DocumentNumberService::class)->allocate((int) $source->id, 'TRF', $uuid);
            $transfer = StockTransfer::query()->forceCreate([
                'uuid' => $uuid,
                'transfer_number' => $number,
                'from_location_id' => $source->id,
                'to_location_id' => $destination->id,
                'status' => 'draft',
                'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->createItems($transfer, $data['items']);

            return $transfer->load(['items.variant.product', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function updateDraft(User $actor, StockTransfer $transfer, array $data): StockTransfer
    {
        $locationIds = $this->validateLocationIds($data);

        return DB::transaction(function () use ($actor, $transfer, $data, $locationIds): StockTransfer {
            ModuleRegistryLock::acquire();
            $record = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->authorizeLocations($actor, StockTransferPermissions::MANAGE, (int) $record->from_location_id, (int) $record->to_location_id,
                $locationIds['from_location_id'], $locationIds['to_location_id']);
            $this->assertDraft($record);
            $this->validateLocations(
                Location::query()->whereKey($locationIds['from_location_id'])->where('is_active', true)->firstOrFail(),
                Location::query()->whereKey($locationIds['to_location_id'])->where('is_active', true)->firstOrFail(),
            );
            $data = $this->validateDraft($data);
            $this->validateAvailability((int) $locationIds['from_location_id'], $data['items']);
            $existingVariantIds = $record->items()->lockForUpdate()->pluck('variant_id')->map(fn ($id): int => (int) $id);
            $submittedVariantIds = collect($data['items'])->pluck('variant_id')->map(fn ($id): int => (int) $id);
            if ($existingVariantIds->diff($submittedVariantIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => __('Existing draft lines cannot be removed.')]);
            }
            $record->fill([
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'notes' => $data['notes'] ?? null,
            ])->save();
            foreach ($data['items'] as $item) {
                StockTransferItem::query()->updateOrCreate(
                    ['stock_transfer_id' => $record->id, 'variant_id' => $item['variant_id']],
                    ['requested_quantity' => $item['requested_quantity'], 'notes' => $item['notes'] ?? null],
                );
            }

            return $record->fresh(['items.variant.product', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function requestApproval(User $actor, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            $record = $this->lockTransfer($transfer);
            app(StockTransferAccess::class)->authorizeTransfer($actor, StockTransferPermissions::MANAGE, $record);
            $this->assertDraft($record);
            $items = $record->items()->orderBy('variant_id')->lockForUpdate()->get(['variant_id', 'requested_quantity']);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => __('A transfer requires at least one product variant.')]);
            }
            $this->validateAvailability((int) $record->from_location_id, $items->map(fn (StockTransferItem $item): array => [
                'variant_id' => (int) $item->variant_id,
                'requested_quantity' => (string) $item->requested_quantity,
            ])->all());
            if ($record->requested_at !== null) {
                throw ValidationException::withMessages(['status' => __('This transfer has already been submitted for approval.')]);
            }
            $record->forceFill(['requested_at' => now()])->save();

            return $record->fresh(['items', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function approve(User $actor, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            $record = $this->lockTransfer($transfer);
            app(StockTransferAccess::class)->authorizeTransfer(
                $actor, StockTransferPermissions::APPROVE, $record, (int) $record->from_location_id,
            );
            if ($record->status !== 'draft' || $record->requested_at === null) {
                throw ValidationException::withMessages(['status' => __('Only a submitted draft can be approved.')]);
            }
            $record->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id])->save();

            return $record->fresh(['items', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function dispatch(User $actor, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            ModuleRegistryLock::acquire();
            $record = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, StockTransferPermissions::DISPATCH, $record, (int) $record->from_location_id);
            if (! in_array($record->status, ['approved', 'in_transit'], true)) {
                throw ValidationException::withMessages(['status' => __('Only approved transfers can be dispatched.')]);
            }
            $items = $record->items()->orderBy('variant_id')->lockForUpdate()->get();
            $remainingItems = [];
            foreach ($items as $item) {
                $alreadyShipped = (string) StockTransferShipmentItem::query()
                    ->where('stock_transfer_item_id', $item->id)
                    ->whereHas('stockTransferShipment', fn ($query) => $query->where('status', 'dispatched'))
                    ->sum('shipped_quantity');
                $remaining = BigDecimal::of($item->requested_quantity)->minus($alreadyShipped);
                if ($remaining->isNegative()) {
                    throw ValidationException::withMessages(['items' => __('Dispatched quantity exceeds the requested quantity.')]);
                }
                if ($remaining->isPositive()) {
                    $remainingItems[] = [
                        'item' => $item,
                        'variant_id' => (int) $item->variant_id,
                        'requested_quantity' => (string) $remaining->toScale(4),
                    ];
                }
            }
            if ($remainingItems === []) {
                throw ValidationException::withMessages(['items' => __('There is no remaining quantity to dispatch.')]);
            }
            $this->validateAvailability((int) $record->from_location_id, array_map(fn (array $line): array => [
                'variant_id' => $line['variant_id'],
                'requested_quantity' => $line['requested_quantity'],
            ], $remainingItems));

            $shipment = StockTransferShipment::query()->create([
                'shipment_number' => 'SH-'.Str::upper((string) Str::uuid7()),
                'stock_transfer_id' => $record->id,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);
            $shipmentItems = [];
            foreach ($remainingItems as $line) {
                $shipmentItems[] = StockTransferShipmentItem::query()->create([
                    'stock_transfer_id' => $record->id,
                    'stock_transfer_shipment_id' => $shipment->id,
                    'stock_transfer_item_id' => $line['item']->id,
                    'shipped_quantity' => $line['requested_quantity'],
                ]);
            }
            app(StockTransferValuationService::class)->dispatch($record, $shipmentItems, $actor);
            $shipment->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatched_by' => $actor->id])->save();
            $record->forceFill(['status' => 'in_transit'])->save();

            return $record->fresh(['items', 'shipments.items', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function receiveNext(User $actor, StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer): StockTransfer {
            ModuleRegistryLock::acquire();
            $record = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, StockTransferPermissions::RECEIVE, $record, (int) $record->to_location_id);
            if (! in_array($record->status, ['in_transit', 'partially_received'], true)) {
                throw ValidationException::withMessages(['status' => __('Only in-transit transfers can be received.')]);
            }
            $shipment = StockTransferShipment::query()->where('stock_transfer_id', $record->id)->where('status', 'dispatched')
                ->whereHas('items', fn ($query) => $query->whereDoesntHave('receiptItems.stockTransferReceipt', fn ($receipt) => $receipt->where('status', 'posted')))
                ->orderBy('id')->lockForUpdate()->first();
            if ($shipment === null) {
                throw ValidationException::withMessages(['items' => __('There is no outstanding shipment to receive.')]);
            }
            $shipmentItems = $shipment->items()->with('stockTransferItem.variant')->orderBy('stock_transfer_item_id')->lockForUpdate()->get();
            $receipt = StockTransferReceipt::query()->create([
                'receipt_number' => 'RC-'.Str::upper((string) Str::uuid7()),
                'stock_transfer_id' => $record->id,
                'stock_transfer_shipment_id' => $shipment->id,
                'status' => 'posted',
                'received_at' => now(),
                'posted_at' => now(),
                'received_by' => $actor->id,
                'posted_by' => $actor->id,
                'created_by' => $actor->id,
            ]);
            $receiptItems = [];
            foreach ($shipmentItems as $shipmentItem) {
                $received = (string) $shipmentItem->receiptItems()
                    ->whereHas('stockTransferReceipt', fn ($query) => $query->where('status', 'posted'))
                    ->sum('received_quantity');
                $remaining = BigDecimal::of($shipmentItem->shipped_quantity)->minus($received);
                if ($remaining->isNegative()) {
                    throw ValidationException::withMessages(['items' => __('Received quantity exceeds the shipped quantity.')]);
                }
                if ($remaining->isPositive()) {
                    $receiptItems[] = StockTransferReceiptItem::query()->create([
                        'stock_transfer_receipt_id' => $receipt->id,
                        'stock_transfer_shipment_id' => $shipment->id,
                        'stock_transfer_shipment_item_id' => $shipmentItem->id,
                        'received_quantity' => (string) $remaining->toScale(4),
                        'damaged_quantity' => '0.0000',
                    ]);
                }
            }
            if ($receiptItems === []) {
                throw ValidationException::withMessages(['items' => __('There is no outstanding quantity to receive.')]);
            }
            app(StockTransferValuationService::class)->receive($record, $receiptItems, $actor);
            $receipt->forceFill(['status' => 'posted'])->save();
            $allShipped = BigDecimal::of((string) $record->shipmentItems()->whereHas('stockTransferShipment', fn ($query) => $query->where('status', 'dispatched'))->sum('shipped_quantity'));
            $allReceived = BigDecimal::of((string) StockTransferReceiptItem::query()->whereHas('stockTransferReceipt', fn ($query) => $query->where('stock_transfer_id', $record->id)->where('status', 'posted'))->sum('received_quantity'));
            $complete = $allShipped->isPositive() && $allShipped->isEqualTo($allReceived)
                && ! $record->items()->whereRaw('requested_quantity > (select coalesce(sum(stock_transfer_shipment_items.shipped_quantity), 0) from stock_transfer_shipment_items join stock_transfer_shipments on stock_transfer_shipments.id = stock_transfer_shipment_items.stock_transfer_shipment_id where stock_transfer_shipment_items.stock_transfer_item_id = stock_transfer_items.id and stock_transfer_shipments.status != ?)', ['cancelled'])->exists();
            $record->forceFill($complete
                ? ['status' => 'received', 'closed_at' => now(), 'closed_by' => $actor->id]
                : ['status' => 'partially_received'])->save();

            return $record->fresh(['items', 'shipments.items.receiptItems', 'receipts.items', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    public function cancel(User $actor, StockTransfer $transfer, ?string $reason = null): StockTransfer
    {
        return DB::transaction(function () use ($actor, $transfer, $reason): StockTransfer {
            $record = $this->lockTransfer($transfer);
            app(StockTransferAccess::class)->authorizeTransfer($actor, StockTransferPermissions::CANCEL, $record);
            if (! in_array($record->status, ['draft', 'approved'], true)
                || StockTransferShipmentItem::query()->where('stock_transfer_id', $record->id)
                    ->whereHas('stockTransferShipment', fn ($query) => $query->where('status', 'dispatched'))->exists()
                || StockMovement::query()->where('reference_type', (new StockTransferShipmentItem)->getMorphClass())
                    ->whereIn('reference_id', $record->shipmentItems()->select('id'))->exists()) {
                throw ValidationException::withMessages(['status' => __('A transfer cannot be cancelled after inventory has left the source location.')]);
            }
            $record->forceFill(['status' => 'cancelled', 'closed_at' => now(), 'closed_by' => $actor->id,
                'notes' => trim(implode("\n", array_filter([$record->notes, $reason]))) ?: null])->save();

            return $record->fresh(['items', 'fromLocation', 'toLocation']);
        }, attempts: 3);
    }

    private function lockTransfer(StockTransfer $transfer): StockTransfer
    {
        ModuleRegistryLock::acquire();

        return StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
    }

    private function authorize(User $actor, string $permission, StockTransfer $transfer, int $permissionLocationId): void
    {
        app(StockTransferAccess::class)->authorizeTransfer($actor, $permission, $transfer, $permissionLocationId);
        app(InventoryAccess::class)->authorizePosting($actor, (int) $transfer->from_location_id);
        app(InventoryAccess::class)->authorizePosting($actor, (int) $transfer->to_location_id);
    }

    private function authorizeLocations(User $actor, string $permission, int ...$locationIds): void
    {
        app(StockTransferAccess::class)->authorize($actor, StockTransferPermissions::VIEW, ...$locationIds);
        app(StockTransferAccess::class)->authorize($actor, $permission, ...$locationIds);
    }

    private function validateDraft(array $data): array
    {
        $validated = Validator::make($data, [
            'from_location_id' => ['required', 'integer', 'different:to_location_id'],
            'to_location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', 'distinct', 'exists:product_variants,id'],
            'items.*.requested_quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();
        $validated['items'] = array_map(fn (array $item): array => [
            'variant_id' => (int) $item['variant_id'],
            'requested_quantity' => (string) BigDecimal::of($item['requested_quantity'])->toScale(4),
            'notes' => $item['notes'] ?? null,
        ], $validated['items']);

        return $validated;
    }

    /**
     * @param  list<array{variant_id: int, requested_quantity: string}>  $items
     */
    private function validateAvailability(int $locationId, array $items): void
    {
        $variantIds = array_values(array_unique(array_column($items, 'variant_id')));
        sort($variantIds, SORT_NUMERIC);
        $balances = StockBalance::query()->where('location_id', $locationId)->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');

        foreach ($items as $index => $item) {
            $balance = $balances->get($item['variant_id']);
            $available = BigDecimal::of($balance?->quantity_available ?? '0.0000');
            if ($balance !== null) {
                $onHand = BigDecimal::of($balance->quantity_on_hand);
                $reserved = BigDecimal::of($balance->quantity_reserved);
                if ($onHand->isNegative() || $reserved->isNegative() || $reserved->isGreaterThan($onHand)
                    || ! $onHand->minus($reserved)->isEqualTo($available)) {
                    throw ValidationException::withMessages([
                        'items' => __('Source stock balance is inconsistent. Reconcile inventory before transferring stock.'),
                    ]);
                }
            }
            if (BigDecimal::of($item['requested_quantity'])->isGreaterThan($available)) {
                throw ValidationException::withMessages([
                    "items.{$index}.requested_quantity" => __('Requested quantity exceeds available stock (:available).', [
                        'available' => QuantityFormatter::format((string) $available),
                    ]),
                ]);
            }
        }
    }

    /** @return array{from_location_id: int, to_location_id: int} */
    private function validateLocationIds(array $data): array
    {
        return Validator::make($data, [
            'from_location_id' => ['required', 'integer', 'different:to_location_id'],
            'to_location_id' => ['required', 'integer'],
        ])->validate();
    }

    private function validateLocations(Location $source, Location $destination): void
    {
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['to_location_id' => __('Source and destination locations must be different.')]);
        }
    }

    private function assertDraft(StockTransfer $transfer): void
    {
        if ($transfer->status !== 'draft' || $transfer->requested_at !== null) {
            throw ValidationException::withMessages(['status' => __('Only an unsubmitted draft can be edited.')]);
        }
    }

    private function createItems(StockTransfer $transfer, array $items): void
    {
        foreach ($items as $item) {
            $transfer->items()->create($item);
        }
    }
}
