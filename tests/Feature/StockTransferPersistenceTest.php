<?php

namespace Tests\Feature;

use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class StockTransferPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'StockTransfer'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_partial_receipts_keep_the_same_shipment_and_transfer_and_load_inverse_relations(): void
    {
        $first = StockTransferReceiptItem::factory()->create(['received_quantity' => '6.0000']);
        $shipmentItem = $first->stockTransferShipmentItem;
        $second = StockTransferReceiptItem::factory()->create([
            'stock_transfer_shipment_item_id' => $shipmentItem->id,
            'received_quantity' => '3.0000',
            'damaged_quantity' => '1.0000',
        ]);
        StockTransferReceiptItem::factory()->create();
        $shipment = $first->stockTransferShipment->fresh(['items', 'receipts.items', 'receiptItems']);
        $transfer = $shipment->stockTransfer->fresh(['items.shipmentItems', 'shipments', 'shipmentItems', 'receipts']);
        $this->assertSame($transfer->id, $first->stockTransferReceipt->stock_transfer_id);
        $this->assertSame($transfer->id, $second->stockTransferReceipt->stock_transfer_id);
        $this->assertSame($shipment->id, $second->stock_transfer_shipment_id);
        $this->assertSame($transfer->id, $shipmentItem->stockTransferItem->stock_transfer_id);
        $this->assertSame([$shipmentItem->stock_transfer_item_id], $transfer->items->modelKeys());
        $this->assertSame([$shipmentItem->id], $transfer->items->first()->shipmentItems->modelKeys());
        $this->assertSame([$shipment->id], $transfer->shipments->modelKeys());
        $this->assertSame([$shipmentItem->id], $transfer->shipmentItems->modelKeys());
        $this->assertSame([$shipmentItem->id], $shipment->items->modelKeys());
        $this->assertSame([$first->id, $second->id], $shipment->receiptItems->modelKeys());
        $this->assertSame([$first->id, $second->id], $shipmentItem->receiptItems->modelKeys());
        $this->assertEqualsCanonicalizing([$first->stock_transfer_receipt_id, $second->stock_transfer_receipt_id], $transfer->receipts->modelKeys());
        $this->assertCount(2, $shipment->receipts);
        $this->assertSame([$first->id], $first->stockTransferReceipt->items->modelKeys());
        $this->assertSame([$transfer->id], $transfer->fromLocation->outgoingStockTransfers->modelKeys());
        $this->assertSame([$transfer->id], $transfer->toLocation->incomingStockTransfers->modelKeys());
        $this->assertNotSame($transfer->from_location_id, $transfer->to_location_id);
        $this->assertSame([$transfer->id], $transfer->createdBy->stockTransfers->modelKeys());
        $this->assertSame([$shipment->id], $shipment->createdBy->stockTransferShipments->modelKeys());
        $this->assertSame([$first->stock_transfer_receipt_id], $first->stockTransferReceipt->createdBy->stockTransferReceipts->modelKeys());
        $this->assertSame([$shipmentItem->stock_transfer_item_id], $shipmentItem->stockTransferItem->variant->stockTransferItems->modelKeys());
    }
}
