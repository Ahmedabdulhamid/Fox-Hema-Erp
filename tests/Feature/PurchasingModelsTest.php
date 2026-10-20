<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Purchasing\Models\VendorBillReceiptMatch;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PurchasingModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'Inventory', 'Purchasing'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_purchase_documents_load_the_complete_chain_and_inverse_relationships(): void
    {
        $match = VendorBillReceiptMatch::factory()->create();
        $match = $match->fresh([
            'vendorBillItem.vendorBill.supplier', 'vendorBillItem.variant',
            'purchaseReceiptItem.purchaseOrderItem.purchaseOrder',
            'purchaseReceiptItem.purchaseReceipt.location',
        ]);
        $billItem = $match->vendorBillItem;
        $bill = $billItem->vendorBill;
        $receiptItem = $match->purchaseReceiptItem;
        $receipt = $receiptItem->purchaseReceipt;
        $orderItem = $receiptItem->purchaseOrderItem;
        $order = $orderItem->purchaseOrder;
        $supplier = $bill->supplier;
        $receiver = User::factory()->create();
        $receipt->receivedBy()->associate($receiver);
        $receipt->save();
        $movement = StockMovement::create([
            'variant_id' => $orderItem->variant_id,
            'location_id' => $order->location_id,
            'type' => 'purchase',
            'quantity' => '10.0000',
        ]);
        $receiptItem->stockMovement()->associate($movement);
        $receiptItem->save();
        $payment = VendorPayment::factory()->create(['supplier_id' => $supplier->id]);
        $allocation = VendorPaymentAllocation::factory()->create([
            'vendor_payment_id' => $payment->id,
            'vendor_bill_id' => $bill->id,
        ]);

        $this->assertTrue($order->supplier->is($supplier));
        $this->assertTrue($bill->purchaseOrder->is($order));
        $this->assertTrue($receipt->purchaseOrder->is($order));
        $this->assertTrue($receipt->location->is($order->location));
        $this->assertTrue($billItem->variant->is($orderItem->variant));
        $this->assertTrue($receipt->fresh(['receivedBy'])->receivedBy->is($receiver));
        $this->assertTrue($receiptItem->fresh(['stockMovement'])->stockMovement->is($movement));
        $this->assertTrue($payment->supplier->is($supplier));
        $this->assertTrue($allocation->vendorPayment->is($payment));
        $this->assertTrue($allocation->vendorBill->is($bill));
        $this->assertSame([$order->id], $supplier->purchaseOrders->modelKeys());
        $this->assertSame([$bill->id], $supplier->vendorBills->modelKeys());
        $this->assertSame([$payment->id], $supplier->vendorPayments->modelKeys());
        $this->assertSame([$orderItem->id], $order->items->modelKeys());
        $this->assertSame([$receipt->id], $order->receipts->modelKeys());
        $this->assertSame([$bill->id], $order->vendorBills->modelKeys());
        $this->assertSame([$receiptItem->id], $orderItem->receiptItems->modelKeys());
        $this->assertSame([$receiptItem->id], $receipt->items->modelKeys());
        $this->assertSame([$billItem->id], $bill->items->modelKeys());
        $this->assertSame([$allocation->id], $bill->paymentAllocations->modelKeys());
        $this->assertSame([$allocation->id], $payment->allocations->modelKeys());
        $this->assertSame([$match->id], $billItem->receiptMatches->modelKeys());
        $this->assertSame([$match->id], $receiptItem->billReceiptMatches->modelKeys());
        $this->assertSame([$order->id], $order->location->purchaseOrders->modelKeys());
        $this->assertSame([$receipt->id], $order->location->purchaseReceipts->modelKeys());
        $this->assertSame([$order->id], $order->createdBy->purchaseOrders->modelKeys());
        $this->assertSame([$receipt->id], $receiver->purchaseReceipts->modelKeys());
        $this->assertSame([$payment->id], $payment->createdBy->vendorPayments->modelKeys());
        $this->assertSame([$orderItem->id], $orderItem->variant->purchaseOrderItems->modelKeys());
        $this->assertSame([$billItem->id], $orderItem->variant->vendorBillItems->modelKeys());
        $this->assertTrue($movement->purchaseReceiptItem->is($receiptItem));
    }

    public function test_partial_receipts_and_supplier_documents_do_not_include_unrelated_records(): void
    {
        $item = PurchaseOrderItem::factory()->create();
        $first = PurchaseReceiptItem::factory()->create(['purchase_order_item_id' => $item->id, 'quantity' => '3.0000']);
        $second = PurchaseReceiptItem::factory()->create(['purchase_order_item_id' => $item->id, 'quantity' => '7.0000']);
        PurchaseReceiptItem::factory()->create();
        VendorBill::factory()->create();
        VendorPayment::factory()->create();
        $order = $item->purchaseOrder->load(['items.receiptItems', 'receipts']);

        $this->assertSame([$item->id], $order->items->modelKeys());
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $order->items->first()->receiptItems->modelKeys());
        $this->assertEqualsCanonicalizing([$first->purchase_receipt_id, $second->purchase_receipt_id], $order->receipts->modelKeys());
        $this->assertSame([$order->id], $order->supplier->purchaseOrders->modelKeys());
        $this->assertTrue($order->supplier->vendorBills->isEmpty());
        $this->assertTrue($order->supplier->vendorPayments->isEmpty());
    }

    public function test_optional_relationships_and_date_casts_are_supported(): void
    {
        $billItem = VendorBillItem::factory()->create();
        $receiptItem = PurchaseReceiptItem::factory()->create();
        $receipt = $receiptItem->purchaseReceipt;
        $order = $receipt->purchaseOrder;
        $bill = $billItem->vendorBill;
        $payment = VendorPayment::factory()->create();
        $this->assertNull($billItem->variant);
        $this->assertNull($bill->purchaseOrder);
        $this->assertNull($receiptItem->stockMovement);
        $this->assertNull($receipt->receivedBy);
        $this->assertNull($receipt->received_at);
        $this->assertNull($bill->due_date);
        $this->assertNull($order->expected_date);

        $order->update(['order_date' => '2026-10-10', 'expected_date' => '2026-10-15']);
        $receipt->update(['received_at' => '2026-10-15 12:30:45']);
        $bill->update(['bill_date' => '2026-10-15', 'due_date' => '2026-11-15']);
        $payment->update(['payment_date' => '2026-11-15']);
        foreach ([
            [$order->fresh(), 'order_date', '2026-10-10'],
            [$order->fresh(), 'expected_date', '2026-10-15'],
            [$bill->fresh(), 'bill_date', '2026-10-15'],
            [$bill->fresh(), 'due_date', '2026-11-15'],
            [$payment->fresh(), 'payment_date', '2026-11-15'],
        ] as [$model, $attribute, $date]) {
            $this->assertInstanceOf(Carbon::class, $model->{$attribute});
            $this->assertSame($date, $model->{$attribute}->toDateString());
        }
        $this->assertInstanceOf(Carbon::class, $receipt->fresh()->received_at);
        $this->assertSame('2026-10-15 12:30:45', $receipt->fresh()->received_at->format('Y-m-d H:i:s'));
    }

    public function test_quantities_prices_and_amounts_preserve_the_schema_precision(): void
    {
        $cases = [
            [PurchaseOrder::class, ['subtotal' => '12.1234', 'tax_amount' => '1.2345', 'total_amount' => '13.3579']],
            [PurchaseOrderItem::class, ['quantity' => '1.2345', 'unit_price' => '12.123456', 'discount_amount' => '0.1234', 'tax_amount' => '1.2345', 'line_total' => '14.9664']],
            [PurchaseReceiptItem::class, ['quantity' => '1.2345', 'unit_cost' => '12.123456']],
            [VendorBill::class, ['subtotal' => '12.1234', 'tax_amount' => '1.2345', 'total_amount' => '13.3579']],
            [VendorBillItem::class, ['quantity' => '1.2345', 'unit_price' => '12.123456', 'tax_amount' => '1.2345', 'line_total' => '14.9664']],
            [VendorPayment::class, ['amount' => '14.9664']],
            [VendorPaymentAllocation::class, ['amount' => '14.9664']],
            [VendorBillReceiptMatch::class, ['matched_quantity' => '1.2345']],
        ];
        foreach ($cases as [$modelClass, $attributes]) {
            $model = $modelClass::factory()->create($attributes)->fresh();
            foreach ($attributes as $attribute => $value) {
                $this->assertSame($value, $model->{$attribute});
            }
        }
        $supplier = Supplier::factory()->create(['is_active' => false])->fresh();
        $this->assertFalse($supplier->is_active);
    }
}
