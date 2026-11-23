<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\StockTransferValuationService;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Services\StockTransferPermissions;
use App\Modules\StockTransfer\Services\StockTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InventoryTestSetup;
use Tests\TestCase;

class StockTransferWorkflowTest extends TestCase
{
    use InventoryTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->artisan('migrate', ['--path' => [
            'app/Modules/InventoryCosting/Database/Migrations',
            'app/Modules/StockTransfer/Database/Migrations',
        ], '--force' => true])->assertExitCode(0);
        Module::query()->whereIn('code', ['stock_transfers', 'inventory_costing'])->update(['is_active' => true]);
        $role = Role::query()->where('location_id', $this->location->id)->firstOrFail();
        $role->givePermissionTo(StockTransferPermissions::all());
        $this->addLocation(Location::query()->create(['name' => 'Branch Two', 'type' => 'branch']));
        $this->warehouse = Location::query()->create(['name' => 'Central Warehouse', 'type' => 'warehouse']);
        $this->addLocation($this->warehouse);
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
    }

    protected Location $warehouse;

    public function test_warehouse_branch_and_branch_branch_transfers_post_each_movement_once(): void
    {
        $branch = $this->location;
        $secondBranch = Location::query()->where('name', 'Branch Two')->firstOrFail();
        foreach ([[$this->warehouse, $branch], [$branch, $secondBranch]] as [$source, $destination]) {
            $variant = ProductVariant::factory()->create();
            $this->postPurchase($variant, $source, '5.0000', 'transfer-test:'.$source->id);
            $transfer = $this->createAndApprove($source, $destination, $variant, '2.0000');
            $service = app(StockTransferService::class);
            $service->dispatch($this->actor, $transfer);
            $this->assertThrows(fn () => $service->dispatch($this->actor, $transfer), ValidationException::class);
            $received = $service->receiveNext($this->actor, $transfer);
            $this->assertSame('received', $received->status);
            $this->assertThrows(fn () => $service->receiveNext($this->actor, $received), ValidationException::class);
            $this->assertSame('3.0000', StockBalance::query()->where('variant_id', $variant->id)->where('location_id', $source->id)->value('quantity_on_hand'));
            $this->assertSame('2.0000', StockBalance::query()->where('variant_id', $variant->id)->where('location_id', $destination->id)->value('quantity_on_hand'));
            $this->assertSame(2, StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])
                ->where('variant_id', $variant->id)->count());
        }
    }

    public function test_insufficient_source_stock_is_rejected_when_saving_and_dispatching(): void
    {
        $variant = ProductVariant::factory()->create();
        $this->postPurchase($variant, $this->warehouse, '1.0000', 'insufficient-stock');
        $service = app(StockTransferService::class);
        $this->assertThrows(fn () => $service->createDraft($this->actor, [
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $this->location->id,
            'items' => [['variant_id' => $variant->id, 'requested_quantity' => '2.0000']],
        ]), ValidationException::class);
        $this->assertThrows(fn () => $service->createDraft($this->actor, [
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $this->warehouse->id,
            'items' => [['variant_id' => $variant->id, 'requested_quantity' => '1.0000']],
        ]), ValidationException::class);
        $this->assertThrows(fn () => $service->createDraft($this->actor, [
            'from_location_id' => $this->warehouse->id,
            'to_location_id' => $this->location->id,
            'items' => [
                ['variant_id' => $variant->id, 'requested_quantity' => '0.6000'],
                ['variant_id' => $variant->id, 'requested_quantity' => '0.6000'],
            ],
        ]), ValidationException::class);
        $this->assertSame(0, StockTransfer::query()->count());

        $this->postPurchase($variant, $this->warehouse, '1.0000', 'dispatch-stock-opening');
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '2.0000');
        app(InventoryPostingService::class)->post(new StockPostingCommand(
            $variant, $this->warehouse, StockMovementType::Sale, '-1.0000', 'stock-consumed-after-approval',
        ), $this->actor);

        $this->assertThrows(fn () => $service->dispatch($this->actor, $transfer), ValidationException::class);
        $this->assertSame('1.0000', StockBalance::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->value('quantity_on_hand'));
        $this->assertSame(0, StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])->count());
        $this->assertSame(0, $transfer->fresh()->shipmentItems()->count());
    }

    public function test_foreign_location_ids_are_rejected_and_cancellation_never_reverses_posted_movements(): void
    {
        $foreign = Location::query()->create(['name' => 'Unauthorized Branch', 'type' => 'branch', 'code' => 'BR-003']);
        $variant = ProductVariant::factory()->create();
        $service = app(StockTransferService::class);
        $this->assertThrows(fn () => $service->createDraft($this->actor, [
            'from_location_id' => $this->location->id,
            'to_location_id' => $foreign->id,
            'items' => [['variant_id' => $variant->id, 'requested_quantity' => '1']],
        ]), HttpException::class);

        $this->postPurchase($variant, $this->warehouse, '3.0000', 'cancel-safety');
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        $cancelled = $service->cancel($this->actor, $transfer, 'No longer required');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(0, StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])->count());

        $dispatched = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        $service->dispatch($this->actor, $dispatched);
        $this->assertThrows(fn () => $service->cancel($this->actor, $dispatched), ValidationException::class);
        $this->assertSame(1, StockMovement::query()->where('type', 'transfer_out')->count());
    }

    public function test_avco_costing_moves_the_same_carrying_value_between_locations(): void
    {
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'avco']);
        $variant = ProductVariant::factory()->create();
        $variant->product()->update(['category_id' => $setting->category_id]);
        $this->postPurchase($variant, $this->warehouse, '5.0000', 'avco-opening');
        InventoryCostState::query()->create([
            'variant_id' => $variant->id,
            'location_id' => $this->warehouse->id,
            'avg_cost' => '4.000000',
            'on_hand_qty' => '5.0000',
            'total_value' => '20.0000',
        ]);
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '2.0000');
        $service = app(StockTransferService::class);
        $service->dispatch($this->actor, $transfer);
        $service->receiveNext($this->actor, $transfer);

        $source = InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->firstOrFail();
        $destination = InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->location->id)->firstOrFail();
        $this->assertSame('3.0000', $source->on_hand_qty);
        $this->assertSame('12.0000', $source->total_value);
        $this->assertSame('2.0000', $destination->on_hand_qty);
        $this->assertSame('8.0000', $destination->total_value);
        $this->assertSame(2, InventoryCostEntry::query()->whereHas('stockMovement', fn ($query) => $query->where('variant_id', $variant->id)
            ->whereIn('type', ['transfer_out', 'transfer_in']))->count());
    }

    public function test_standard_partial_transfer_preserves_rounded_closing_value(): void
    {
        $variant = $this->standardOpening();
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        app(StockTransferService::class)->dispatch($this->actor, $transfer);

        $this->assertSame('0.3333', InventoryCostState::query()->where('variant_id', $variant->id)
            ->where('location_id', $this->warehouse->id)->value('total_value'));
        $this->assertSame('-0.3334', InventoryCostEntry::query()->where('entry_type', 'issue')->value('total_cost'));
    }

    public function test_standard_repeated_full_and_return_transfers_reconcile_variance_without_rewriting_entries(): void
    {
        $variant = $this->standardOpening();
        $service = app(StockTransferService::class);
        $secondBranch = Location::query()->where('name', 'Branch Two')->firstOrFail();
        $history = [];
        foreach ([[$this->warehouse, $this->location, '1.0000', '0.3333', '0.3333', '0.0001'],
            [$this->warehouse, $this->location, '1.0000', '0.0000', '0.6667', '-0.0001'],
            [$this->location, $secondBranch, '2.0000', '0.0000', '0.6667', '0.0000'],
            [$secondBranch, $this->warehouse, '2.0000', '0.0000', '0.6667', '0.0000']] as [$source, $destination, $quantity, $sourceValue, $destinationValue, $variance]) {
            $transfer = $this->createAndApprove($source, $destination, $variant, $quantity);
            $service->dispatch($this->actor, $transfer);
            $this->assertThrows(fn () => $service->dispatch($this->actor, $transfer), ValidationException::class);
            $service->receiveNext($this->actor, $transfer);
            $this->assertThrows(fn () => $service->receiveNext($this->actor, $transfer), ValidationException::class);
            $this->assertSame($sourceValue, InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $source->id)->value('total_value'));
            $this->assertSame($destinationValue, InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $destination->id)->value('total_value'));
            $receipt = InventoryCostEntry::query()->latest('id')->firstOrFail();
            $this->assertSame('0.333333', $receipt->unit_cost);
            $this->assertSame($variance, $receipt->purchase_price_variance);
            $this->assertEqualsWithDelta((float) $receipt->purchase_value, (float) $receipt->total_cost + (float) $variance, 0.000001);
            foreach ($history as $id => $attributes) {
                $this->assertSame($attributes, InventoryCostEntry::query()->findOrFail($id)->getAttributes());
            }
            $history += InventoryCostEntry::query()->get()->mapWithKeys(fn ($entry): array => [$entry->id => $entry->getAttributes()])->all();
            $movement = $receipt->stockMovement;
            $this->assertThrows(fn () => app(InventoryPostingService::class)->reverse($movement, 'forbidden-reversal:'.$movement->id, $this->actor, 'Test reversal'), ValidationException::class);
            $this->assertThrows(fn () => $service->cancel($this->actor, $transfer), ValidationException::class);
        }
        $this->assertSame(8, InventoryCostEntry::query()->count());
        $this->assertSame(8, StockMovement::query()->whereIn('type', ['transfer_in', 'transfer_out'])->count());
    }

    public function test_standard_partial_receipts_allocate_source_value_independently_of_destination_value(): void
    {
        $variant = $this->standardOpening();
        $service = app(StockTransferService::class);
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '2.0000');
        $service->dispatch($this->actor, $transfer);
        $shipment = $transfer->shipmentItems()->firstOrFail();
        foreach (['0.3333', '0.6667'] as $closingValue) {
            DB::transaction(function () use ($transfer, $shipment): void {
                $receipt = StockTransferReceipt::factory()->create(['stock_transfer_id' => $transfer->id,
                    'stock_transfer_shipment_id' => $shipment->stock_transfer_shipment_id, 'status' => 'posted']);
                $item = StockTransferReceiptItem::factory()->create(['stock_transfer_receipt_id' => $receipt->id,
                    'stock_transfer_shipment_id' => $shipment->stock_transfer_shipment_id,
                    'stock_transfer_shipment_item_id' => $shipment->id, 'received_quantity' => '1.0000']);
                app(StockTransferValuationService::class)->receive($transfer, [$item], $this->actor);
            });
            $this->assertSame($closingValue, InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->location->id)->value('total_value'));
        }
        $this->assertSame(['0.3334', '0.3333'], InventoryCostEntry::query()->where('entry_type', 'receipt')->orderBy('id')->pluck('purchase_value')->all());
        $this->assertSame(['0.0001', '-0.0001'], InventoryCostEntry::query()->where('entry_type', 'receipt')->orderBy('id')->pluck('purchase_price_variance')->all());
    }

    public function test_transfer_posting_requires_access_to_both_locations(): void
    {
        $variant = $this->standardOpening();
        $service = app(StockTransferService::class);
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        foreach ([$this->warehouse, $this->location] as $location) {
            $this->actor->locations()->detach($location);
            $this->assertThrows(fn () => $service->dispatch($this->actor, $transfer), HttpException::class);
            $this->assertSame(0, $transfer->shipmentItems()->count());
            $this->actor->locations()->attach($location);
        }
        $service->dispatch($this->actor, $transfer);
        foreach ([$this->warehouse, $this->location] as $location) {
            $this->actor->locations()->detach($location);
            $this->assertThrows(fn () => $service->receiveNext($this->actor, $transfer), HttpException::class);
            $this->assertSame(0, $transfer->receipts()->count());
            $this->actor->locations()->attach($location);
        }
        $service->receiveNext($this->actor, $transfer);
    }

    public function test_standard_transfer_rolls_back_stock_entries_and_documents_when_state_save_fails(): void
    {
        $variant = $this->standardOpening();
        $service = app(StockTransferService::class);
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        foreach (['dispatch', 'receiveNext'] as $operation) {
            $dispatcher = InventoryCostState::getEventDispatcher();
            InventoryCostState::setEventDispatcher(clone $dispatcher);
            InventoryCostState::saving(function (): never {
                throw new \RuntimeException('Injected state persistence failure');
            });
            try {
                $this->assertThrows(fn () => $service->{$operation}($this->actor, $transfer), \RuntimeException::class);
            } finally {
                InventoryCostState::setEventDispatcher($dispatcher);
            }
            $dispatched = $operation === 'receiveNext';
            $this->assertSame($dispatched ? 1 : 0, InventoryCostEntry::query()->count());
            $this->assertSame($dispatched ? 1 : 0, $transfer->shipmentItems()->count());
            $this->assertSame(0, $transfer->receipts()->count());
            $this->assertSame($dispatched ? 'in_transit' : 'approved', $transfer->fresh()->status);
            $this->assertSame($dispatched ? '0.3333' : '0.6667', InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->value('total_value'));
            $this->assertSame($dispatched ? '1.0000' : '2.0000', StockBalance::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->value('quantity_on_hand'));
            $this->assertSame($dispatched ? 1 : 0, StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])->count());
            $service->{$operation}($this->actor, $transfer);
        }
        $this->assertSame('received', $transfer->fresh()->status);
        $this->assertSame(2, InventoryCostEntry::query()->count());
    }

    public function test_standard_receipt_rejects_unreconciled_destination_and_changed_revision_atomically(): void
    {
        $variant = $this->standardOpening();
        $service = app(StockTransferService::class);
        $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
        $service->dispatch($this->actor, $transfer);
        $destination = InventoryCostState::query()->create(['variant_id' => $variant->id,
            'location_id' => $this->location->id, 'on_hand_qty' => '0.0000', 'total_value' => '0.0001', 'avg_cost' => '0.000000']);
        $this->assertThrows(fn () => $service->receiveNext($this->actor, $transfer), ValidationException::class);
        $destination->update(['total_value' => '0.0000']);
        $standard = InventoryStandardCost::query()->where('variant_id', $variant->id)->firstOrFail();
        $standard->revisions()->create(['old_cost' => '0.333333', 'new_cost' => '0.500000',
            'reason' => 'Changed while in transit', 'changed_by' => $this->actor->id, 'effective_at' => now()->subMinute()]);
        $this->assertThrows(fn () => $service->receiveNext($this->actor, $transfer), ValidationException::class);
        $this->assertSame(0, $transfer->receipts()->count());
        $this->assertSame(1, InventoryCostEntry::query()->count());
        $this->assertSame(0, StockMovement::query()->where('type', 'transfer_in')->count());
        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame('0.0000', $destination->fresh()->total_value);
    }

    public function test_avco_fractional_transfers_preserve_source_value(): void
    {
        $variant = $this->standardOpening();
        InventoryValuationSetting::query()->update(['costing_method' => 'avco']);
        foreach (['0.3333', '0.0000'] as $sourceValue) {
            $transfer = $this->createAndApprove($this->warehouse, $this->location, $variant, '1.0000');
            app(StockTransferService::class)->dispatch($this->actor, $transfer);
            app(StockTransferService::class)->receiveNext($this->actor, $transfer);
            $this->assertSame($sourceValue, InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->value('total_value'));
        }
        $this->assertSame('0.6667', InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->location->id)->value('total_value'));
        $this->assertSame(0, InventoryCostEntry::query()->whereNotNull('purchase_price_variance')->count());
    }

    public function test_fifo_fractional_transfer_preserves_exact_layer_values(): void
    {
        $variant = $this->standardOpening();
        InventoryValuationSetting::query()->update(['costing_method' => 'fifo']);
        $movement = StockMovement::query()->where('variant_id', $variant->id)->firstOrFail();
        $layer = FifoLayer::factory()->create(['variant_id' => $variant->id, 'stock_movement_id' => $movement->id,
            'quantity' => '2.0000', 'remaining_qty' => '2.0000', 'unit_cost' => '0.333333']);
        DB::table('fifo_layers')->where('id', $layer->id)->update(['initial_value' => '0.6667', 'remaining_value' => '0.6667', 'available_at' => now()]);
        foreach ([[$this->warehouse, $this->location, '2.0000'],
            [$this->location, $this->warehouse, '1.0000'],
            [$this->location, $this->warehouse, '1.0000']] as [$source, $destination, $quantity]) {
            $transfer = $this->createAndApprove($source, $destination, $variant, $quantity);
            app(StockTransferService::class)->dispatch($this->actor, $transfer);
            app(StockTransferService::class)->receiveNext($this->actor, $transfer);
        }
        $this->assertSame('0.0000', $layer->fresh()->remaining_value);
        $this->assertSame('0.6667', InventoryCostState::query()->where('variant_id', $variant->id)->where('location_id', $this->warehouse->id)->value('total_value'));
    }

    private function standardOpening(): ProductVariant
    {
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'standard']);
        $variant = ProductVariant::factory()->create();
        $variant->product()->update(['category_id' => $setting->category_id]);
        $this->postPurchase($variant, $this->warehouse, '2.0000', 'standard-opening');
        $standard = InventoryStandardCost::factory()->create(['variant_id' => $variant->id, 'standard_cost' => '0.333333']);
        $standard->revisions()->create(['old_cost' => '0', 'new_cost' => '0.333333',
            'reason' => 'Opening standard', 'changed_by' => $this->actor->id, 'effective_at' => now()->subDay()]);
        InventoryCostState::query()->create(['variant_id' => $variant->id, 'location_id' => $this->warehouse->id,
            'avg_cost' => '0.333350', 'on_hand_qty' => '2.0000', 'total_value' => '0.6667']);

        return $variant;
    }

    private function addLocation(Location $location): void
    {
        $this->actor->locations()->attach($location);
        app(LocationContext::class)->setFor($this->actor, $location->id);
        $role = Role::query()->create([
            'name' => 'transfer-manager-'.$location->id,
            'guard_name' => 'web',
            'location_id' => $location->id,
        ]);
        $role->givePermissionTo([...InventoryPermissions::all(), ...StockTransferPermissions::all()]);
        $this->actor->assignRole($role);
    }

    private function postPurchase(ProductVariant $variant, Location $location, string $quantity, string $identity): void
    {
        app(InventoryPostingService::class)->post(new StockPostingCommand(
            $variant, $location, StockMovementType::Purchase, $quantity, $identity,
        ), $this->actor);
    }

    private function createAndApprove(Location $source, Location $destination, ProductVariant $variant, string $quantity): StockTransfer
    {
        $service = app(StockTransferService::class);
        $transfer = $service->createDraft($this->actor, [
            'from_location_id' => $source->id,
            'to_location_id' => $destination->id,
            'items' => [['variant_id' => $variant->id, 'requested_quantity' => $quantity]],
        ]);
        $service->requestApproval($this->actor, $transfer);

        return $service->approve($this->actor, $transfer);
    }
}
