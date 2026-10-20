<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Models\VendorPaymentAllocation;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingModelChangesTest extends TestCase
{
    public function test_supplier_details_and_payment_terms_accept_mass_assignment(): void
    {
        $supplier = new Supplier([
            'code' => 'SUP-001', 'name' => 'Supplier', 'email' => 'supplier@example.test',
            'address' => 'Cairo', 'tax_number' => 'TAX-001',
            'payment_terms_days' => '30', 'notes' => 'Monthly settlement', 'is_active' => 0,
        ]);
        $this->assertSame('SUP-001', $supplier->code);
        $this->assertSame('supplier@example.test', $supplier->email);
        $this->assertSame('Cairo', $supplier->address);
        $this->assertSame('TAX-001', $supplier->tax_number);
        $this->assertSame(30, $supplier->payment_terms_days);
        $this->assertSame('Monthly settlement', $supplier->notes);
        $this->assertFalse($supplier->is_active);
    }

    public function test_new_money_and_audit_fields_preserve_precision_dates_and_nulls(): void
    {
        foreach ([
            [PurchaseOrder::class, ['discount_amount' => '1.2345', 'notes' => 'Order notes'], ['confirmed_at']],
            [PurchaseOrderItem::class, ['subtotal' => '12.3456', 'notes' => 'Item notes'], []],
            [PurchaseReceipt::class, ['notes' => 'Receipt notes'], ['posted_at']],
            [VendorBill::class, ['discount_amount' => '1.2345', 'notes' => 'Bill notes'], ['posted_at']],
            [VendorBillItem::class, ['subtotal' => '12.3456', 'discount_amount' => '1.2345'], []],
            [VendorPayment::class, ['notes' => 'Payment notes'], ['posted_at', 'voided_at']],
        ] as [$modelClass, $attributes, $dateFields]) {
            $model = new $modelClass($attributes);
            foreach ($attributes as $field => $value) {
                $this->assertSame($value, $model->{$field});
            }
            foreach ($dateFields as $field) {
                $this->assertNull($model->{$field});
                $model->fill([$field => '2026-10-11 12:30:45']);
                $this->assertInstanceOf(Carbon::class, $model->{$field});
                $this->assertSame('2026-10-11 12:30:45', $model->{$field}->format('Y-m-d H:i:s'));
            }
        }
        $this->assertSame('closed', (new PurchaseOrder(['status' => 'closed']))->status);
    }

    public function test_audit_relationships_associate_and_dissociate_the_correct_user_fields(): void
    {
        $user = (new User)->forceFill(['id' => 42]);
        foreach ([
            [PurchaseOrder::class, 'confirmedBy', 'confirmed_by'],
            [PurchaseReceipt::class, 'postedBy', 'posted_by'],
            [VendorBill::class, 'createdBy', 'created_by'],
            [VendorBill::class, 'postedBy', 'posted_by'],
            [VendorPayment::class, 'postedBy', 'posted_by'],
            [VendorPayment::class, 'voidedBy', 'voided_by'],
        ] as [$modelClass, $relation, $foreignKey]) {
            $model = new $modelClass;
            $model->{$relation}()->associate($user);
            $this->assertSame(42, $model->{$foreignKey});
            $this->assertTrue($model->{$relation}->is($user));
            $model->{$relation}()->dissociate();
            $this->assertNull($model->{$foreignKey});
            $this->assertNull($model->{$relation});
        }
    }

    public function test_receipt_order_and_allocation_supplier_links_use_the_added_foreign_keys(): void
    {
        $order = (new PurchaseOrder)->forceFill(['id' => 11]);
        $item = new PurchaseReceiptItem;
        $item->purchaseOrder()->associate($order);
        $this->assertSame(11, $item->purchase_order_id);
        $this->assertTrue($item->purchaseOrder->is($order));
        $supplier = (new Supplier)->forceFill(['id' => 22]);
        $allocation = new VendorPaymentAllocation;
        $allocation->supplier()->associate($supplier);
        $this->assertSame(22, $allocation->supplier_id);
        $this->assertTrue($allocation->supplier->is($supplier));
        $this->assertSame('purchase_receipt_items.purchase_order_id', $order->receiptItems()->getQualifiedForeignKeyName());
        $this->assertSame('vendor_payment_allocations.supplier_id', $supplier->paymentAllocations()->getQualifiedForeignKeyName());
        $this->assertInstanceOf(HasOne::class, (new StockMovement)->purchaseReceiptItem());
    }
}
