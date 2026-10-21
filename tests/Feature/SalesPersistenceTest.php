<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNoteItem;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class SalesPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** Persistence-only legacy fixture: no claim that these documents were operationally posted. */
    private function historicalMatch(): SalesInvoiceDeliveryMatch
    {
        $orderItem = SalesOrderItem::factory()->create();
        $delivery = SalesDelivery::factory()->make(['sales_order_id' => $orderItem->sales_order_id,
            'location_id' => $orderItem->salesOrder->location_id]);
        $deliveryId = DB::table('sales_deliveries')->insertGetId($delivery->getAttributes() +
            ['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        $item = SalesDeliveryItem::factory()->make(['sales_order_id' => $orderItem->sales_order_id,
            'sales_order_item_id' => $orderItem->id, 'sales_delivery_id' => $deliveryId]);
        $itemId = DB::table('sales_delivery_items')->insertGetId($item->getAttributes() +
            ['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);

        return SalesInvoiceDeliveryMatch::factory()->create(['sales_delivery_item_id' => $itemId]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'Inventory', 'Sales'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_sales_documents_persist_and_eager_load_the_full_return_and_credit_chain(): void
    {
        $match = $this->historicalMatch();
        $invoiceItem = $match->salesInvoiceItem;
        $deliveryItem = $match->salesDeliveryItem;
        $invoice = $invoiceItem->salesInvoice;
        $order = $deliveryItem->salesOrder;
        $variant = $deliveryItem->salesOrderItem->variant;
        $movement = StockMovement::create([
            'variant_id' => $variant->id,
            'location_id' => $order->location_id,
            'type' => 'sale',
            'quantity' => '-10.0000',
        ]);
        DB::table('sales_delivery_items')->where('id', $deliveryItem->id)->update(['stock_movement_id' => $movement->id]);
        $deliveryItem->refresh();
        $returnItem = SalesReturnItem::factory()->create([
            'sales_return_id' => SalesReturn::factory()->create([
                'sales_order_id' => $order->id, 'location_id' => $order->location_id, 'sales_invoice_id' => $invoice->id,
            ])->id,
            'sales_delivery_item_id' => $deliveryItem->id,
            'sales_invoice_item_id' => $invoiceItem->id,
        ]);
        $creditNote = SalesCreditNote::factory()->make([
            'sales_invoice_id' => $invoice->id, 'sales_return_id' => $returnItem->sales_return_id, 'location_id' => $order->location_id,
        ]);
        $creditNoteId = DB::table('sales_credit_notes')->insertGetId($creditNote->getAttributes() +
            ['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        $creditItem = SalesCreditNoteItem::factory()->make([
            'sales_credit_note_id' => $creditNoteId,
            'sales_invoice_item_id' => $invoiceItem->id,
            'sales_return_item_id' => $returnItem->id,
        ]);
        $creditItemId = DB::table('sales_credit_note_items')->insertGetId($creditItem->getAttributes() +
            ['uuid' => (string) Str::uuid7(), 'created_at' => now(), 'updated_at' => now()]);
        $creditItem = SalesCreditNoteItem::findOrFail($creditItemId);
        $creditNote = $creditItem->salesCreditNote;
        $this->historicalMatch();

        $order = $order->fresh(['items.deliveryItems', 'deliveries.items', 'invoices.items', 'returns.items']);
        $invoice = $invoice->fresh(['items.deliveryMatches', 'returns', 'creditNotes.items']);
        $this->assertSame([$deliveryItem->sales_order_item_id], $order->items->modelKeys());
        $this->assertSame([$deliveryItem->id], $order->items->first()->deliveryItems->modelKeys());
        $this->assertSame([$deliveryItem->sales_delivery_id], $order->deliveries->modelKeys());
        $this->assertSame([$invoice->id], $order->invoices->modelKeys());
        $this->assertSame([$returnItem->sales_return_id], $order->returns->modelKeys());
        $this->assertSame([$match->id], $invoice->items->first()->deliveryMatches->modelKeys());
        $this->assertSame([$creditNote->id], $invoice->creditNotes->modelKeys());
        $this->assertSame([$creditItem->id], $invoice->creditNotes->first()->items->modelKeys());
        $this->assertSame([$returnItem->id], $deliveryItem->returnItems->modelKeys());
        $this->assertSame([$creditItem->id], $returnItem->creditNoteItems->modelKeys());
        $this->assertSame([$creditNote->id], $returnItem->salesReturn->creditNotes->modelKeys());
        $this->assertTrue($movement->salesDeliveryItem->is($deliveryItem));
        $this->assertSame([$order->id], $order->location->salesOrders->modelKeys());
        $this->assertSame([$order->id], $order->createdBy->salesOrders->modelKeys());
        $this->assertSame([$deliveryItem->sales_order_item_id], $variant->salesOrderItems->modelKeys());
        $this->assertSame([$invoiceItem->id], $variant->salesInvoiceItems->modelKeys());
        $this->assertSame([$returnItem->id], $variant->salesReturnItems->modelKeys());
    }
}
