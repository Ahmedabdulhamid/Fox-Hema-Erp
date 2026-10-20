<?php

namespace App\Modules\Purchasing\Database\Factories;

use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Purchasing\Models\VendorBillReceiptMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VendorBillReceiptMatch> */
class VendorBillReceiptMatchFactory extends Factory
{
    protected $model = VendorBillReceiptMatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_receipt_item_id' => PurchaseReceiptItem::factory(),
            'vendor_bill_item_id' => function (array $attributes): int {
                $receiptItem = PurchaseReceiptItem::findOrFail($attributes['purchase_receipt_item_id']);
                $order = $receiptItem->purchaseOrderItem->purchaseOrder;
                $bill = VendorBill::factory()->create([
                    'supplier_id' => $order->supplier_id,
                    'purchase_order_id' => $order->id,
                ]);

                return VendorBillItem::factory()->create([
                    'vendor_bill_id' => $bill->id,
                    'variant_id' => $receiptItem->purchaseOrderItem->variant_id,
                ])->getKey();
            },
            'matched_quantity' => '10.0000',
        ];
    }
}
