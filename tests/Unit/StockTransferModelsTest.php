<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StockTransferModelsTest extends TestCase
{
    public function test_factories_accept_existing_parent_keys_without_creating_unrelated_records(): void
    {
        foreach ([
            [StockTransfer::class, ['from_location_id' => 42, 'to_location_id' => 42, 'created_by' => 42, 'approved_by' => 42, 'closed_by' => 42]],
            [StockTransferItem::class, ['stock_transfer_id' => 42, 'variant_id' => 42]],
            [StockTransferShipment::class, ['stock_transfer_id' => 42, 'dispatched_by' => 42, 'created_by' => 42, 'cancelled_by' => 42]],
            [StockTransferShipmentItem::class, ['stock_transfer_id' => 42, 'stock_transfer_shipment_id' => 42, 'stock_transfer_item_id' => 42]],
            [StockTransferReceipt::class, ['stock_transfer_id' => 42, 'stock_transfer_shipment_id' => 42, 'received_by' => 42, 'posted_by' => 42, 'created_by' => 42]],
            [StockTransferReceiptItem::class, ['stock_transfer_receipt_id' => 42, 'stock_transfer_shipment_id' => 42, 'stock_transfer_shipment_item_id' => 42]],
        ] as [$modelClass, $owners]) {
            $model = $modelClass::factory()->make($owners);
            $this->assertInstanceOf($modelClass, $model);
            foreach ($owners as $key => $value) {
                $this->assertSame($value, $model->{$key});
            }
        }
    }

    public function test_quantities_keep_four_decimal_places_including_zero_damage(): void
    {
        $item = new StockTransferItem(['requested_quantity' => '12.3456']);
        $shipmentItem = new StockTransferShipmentItem(['shipped_quantity' => '8.1234']);
        $receiptItem = new StockTransferReceiptItem(['received_quantity' => '7.1234', 'damaged_quantity' => 0]);
        $this->assertSame('12.3456', $item->requested_quantity);
        $this->assertSame('8.1234', $shipmentItem->shipped_quantity);
        $this->assertSame('7.1234', $receiptItem->received_quantity);
        $this->assertSame('0.0000', $receiptItem->damaged_quantity);
        $receiptItem->damaged_quantity = '1.0001';
        $this->assertSame('1.0001', $receiptItem->damaged_quantity);
    }

    public function test_lifecycle_timestamps_cast_values_and_preserve_nulls(): void
    {
        foreach ([
            [StockTransfer::class, ['requested_at', 'approved_at', 'closed_at']],
            [StockTransferShipment::class, ['dispatched_at', 'cancelled_at']],
            [StockTransferReceipt::class, ['received_at', 'posted_at']],
        ] as [$modelClass, $fields]) {
            $model = new $modelClass;
            foreach ($fields as $field) {
                $model->fill([$field => '2026-10-11 12:30:45']);
                $this->assertInstanceOf(Carbon::class, $model->{$field});
                $this->assertSame('2026-10-11 12:30:45', $model->{$field}->format('Y-m-d H:i:s'));
                $model->fill([$field => null]);
                $this->assertNull($model->{$field});
            }
        }
    }

    public function test_audit_actors_associate_the_correct_user_keys(): void
    {
        $actor = (new User)->forceFill(['id' => 42]);
        foreach ([
            [StockTransfer::class, 'createdBy', 'created_by'],
            [StockTransfer::class, 'approvedBy', 'approved_by'],
            [StockTransfer::class, 'closedBy', 'closed_by'],
            [StockTransferShipment::class, 'dispatchedBy', 'dispatched_by'],
            [StockTransferShipment::class, 'createdBy', 'created_by'],
            [StockTransferShipment::class, 'cancelledBy', 'cancelled_by'],
            [StockTransferReceipt::class, 'receivedBy', 'received_by'],
            [StockTransferReceipt::class, 'postedBy', 'posted_by'],
            [StockTransferReceipt::class, 'createdBy', 'created_by'],
        ] as [$modelClass, $relation, $key]) {
            $model = new $modelClass;
            $model->{$relation}()->associate($actor);
            $this->assertSame(42, $model->{$key});
            $this->assertTrue($model->{$relation}->is($actor));
        }
        $shipment = new StockTransferShipment;
        $shipment->cancelledBy()->associate($actor);
        $shipment->cancelledBy()->dissociate();
        $this->assertNull($shipment->cancelled_by);
        $this->assertNull($shipment->cancelledBy);
    }

    public function test_source_and_destination_use_separate_location_keys(): void
    {
        $source = (new Location)->forceFill(['id' => 12]);
        $destination = (new Location)->forceFill(['id' => 13]);
        $transfer = new StockTransfer;
        $transfer->fromLocation()->associate($source);
        $transfer->toLocation()->associate($destination);
        $this->assertSame(12, $transfer->from_location_id);
        $this->assertSame(13, $transfer->to_location_id);
        $this->assertTrue($transfer->fromLocation->is($source));
        $this->assertTrue($transfer->toLocation->is($destination));
        $this->assertSame('stock_transfers.from_location_id', $source->outgoingStockTransfers()->getQualifiedForeignKeyName());
        $this->assertSame('stock_transfers.to_location_id', $destination->incomingStockTransfers()->getQualifiedForeignKeyName());
    }
}
