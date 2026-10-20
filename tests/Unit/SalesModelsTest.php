<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNoteItem;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesDeliveryItem;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceDeliveryMatch;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesModelsTest extends TestCase
{
    public function test_factories_make_each_document_with_supplied_owner_keys(): void
    {
        $cases = [
            [SalesOrder::class, ['location_id' => 42, 'confirmed_by' => 42, 'created_by' => 42]],
            [SalesOrderItem::class, ['sales_order_id' => 42, 'variant_id' => 42]],
            [SalesDelivery::class, ['sales_order_id' => 42, 'location_id' => 42, 'posted_by' => 42, 'created_by' => 42]],
            [SalesDeliveryItem::class, ['sales_order_id' => 42, 'sales_delivery_id' => 42, 'sales_order_item_id' => 42, 'stock_movement_id' => 42]],
            [SalesInvoice::class, ['sales_order_id' => 42, 'location_id' => 42, 'posted_by' => 42, 'created_by' => 42]],
            [SalesInvoiceItem::class, ['sales_invoice_id' => 42, 'sales_order_item_id' => 42, 'variant_id' => 42]],
            [SalesInvoiceDeliveryMatch::class, ['sales_invoice_item_id' => 42, 'sales_delivery_item_id' => 42]],
            [SalesReturn::class, ['sales_order_id' => 42, 'sales_invoice_id' => 42, 'location_id' => 42, 'created_by' => 42, 'approved_by' => 42, 'posted_by' => 42, 'cancelled_by' => 42]],
            [SalesReturnItem::class, ['sales_return_id' => 42, 'sales_delivery_item_id' => 42, 'sales_invoice_item_id' => 42, 'variant_id' => 42]],
            [SalesCreditNote::class, ['sales_invoice_id' => 42, 'sales_return_id' => 42, 'created_by' => 42, 'posted_by' => 42, 'cancelled_by' => 42]],
            [SalesCreditNoteItem::class, ['sales_credit_note_id' => 42, 'sales_invoice_item_id' => 42, 'sales_return_item_id' => 42]],
        ];
        foreach ($cases as [$modelClass, $owners]) {
            $model = $modelClass::factory()->make($owners);
            $this->assertInstanceOf($modelClass, $model);
            foreach ($owners as $field => $value) {
                $this->assertSame($value, $model->{$field});
            }
        }
    }

    public function test_money_and_quantity_casts_preserve_the_migration_precision(): void
    {
        foreach ([
            [SalesOrder::class, ['subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'total_amount' => '12.1234']],
            [SalesOrderItem::class, ['quantity' => '12.1234', 'unit_price' => '12.123456', 'subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'line_total' => '12.1234']],
            [SalesDeliveryItem::class, ['quantity' => '12.1234']],
            [SalesInvoice::class, ['subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'total_amount' => '12.1234']],
            [SalesInvoiceItem::class, ['quantity' => '12.1234', 'unit_price' => '12.123456', 'subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'line_total' => '12.1234']],
            [SalesInvoiceDeliveryMatch::class, ['matched_quantity' => '12.1234']],
            [SalesReturnItem::class, ['quantity' => '12.1234', 'unit_price' => '12.123456', 'refund_amount' => '12.1234']],
            [SalesCreditNote::class, ['subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'total_amount' => '12.1234']],
            [SalesCreditNoteItem::class, ['quantity' => '12.1234', 'unit_price' => '12.123456', 'subtotal' => '12.1234', 'discount_amount' => '12.1234', 'tax_amount' => '12.1234', 'line_total' => '12.1234']],
        ] as [$modelClass, $attributes]) {
            $model = new $modelClass($attributes);
            foreach ($attributes as $field => $value) {
                $this->assertSame($value, $model->{$field});
            }
        }
    }

    public function test_document_dates_and_lifecycle_timestamps_accept_nulls_and_cast_values(): void
    {
        foreach ([
            [SalesOrder::class, ['ordered_at' => 'datetime', 'confirmed_at' => 'datetime']],
            [SalesDelivery::class, ['delivered_at' => 'datetime', 'posted_at' => 'datetime']],
            [SalesInvoice::class, ['invoice_date' => 'date', 'due_date' => 'date', 'posted_at' => 'datetime']],
            [SalesReturn::class, ['return_date' => 'date', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime']],
            [SalesCreditNote::class, ['credit_date' => 'date', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime']],
        ] as [$modelClass, $fields]) {
            $model = new $modelClass;
            foreach ($fields as $field => $cast) {
                $model->fill([$field => '2026-10-11 12:30:45']);
                $this->assertInstanceOf(Carbon::class, $model->{$field});
                $format = $cast === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
                $expected = $cast === 'date' ? '2026-10-11' : '2026-10-11 12:30:45';
                $this->assertSame($expected, $model->{$field}->format($format));
                $model->fill([$field => null]);
                $this->assertNull($model->{$field});
            }
        }
    }

    public function test_user_attribution_relationships_associate_the_correct_foreign_keys(): void
    {
        $actor = (new User)->forceFill(['id' => 42]);
        foreach ([
            [SalesOrder::class, 'confirmedBy', 'confirmed_by'],
            [SalesOrder::class, 'createdBy', 'created_by'],
            [SalesDelivery::class, 'postedBy', 'posted_by'],
            [SalesDelivery::class, 'createdBy', 'created_by'],
            [SalesInvoice::class, 'postedBy', 'posted_by'],
            [SalesInvoice::class, 'createdBy', 'created_by'],
            [SalesReturn::class, 'createdBy', 'created_by'],
            [SalesReturn::class, 'approvedBy', 'approved_by'],
            [SalesReturn::class, 'postedBy', 'posted_by'],
            [SalesReturn::class, 'cancelledBy', 'cancelled_by'],
            [SalesCreditNote::class, 'createdBy', 'created_by'],
            [SalesCreditNote::class, 'postedBy', 'posted_by'],
            [SalesCreditNote::class, 'cancelledBy', 'cancelled_by'],
        ] as [$modelClass, $relation, $key]) {
            $model = new $modelClass;
            $model->{$relation}()->associate($actor);
            $this->assertSame(42, $model->{$key});
            $this->assertTrue($model->{$relation}->is($actor));
        }
    }

    public function test_matching_returns_and_credit_notes_associate_the_correct_documents(): void
    {
        $order = (new SalesOrder)->forceFill(['id' => 1]);
        $invoice = (new SalesInvoice)->forceFill(['id' => 2]);
        $invoiceItem = (new SalesInvoiceItem)->forceFill(['id' => 3]);
        $deliveryItem = (new SalesDeliveryItem)->forceFill(['id' => 4]);
        $return = (new SalesReturn)->forceFill(['id' => 5]);
        $returnItem = (new SalesReturnItem)->forceFill(['id' => 6]);
        $creditNote = (new SalesCreditNote)->forceFill(['id' => 7]);
        $creditItem = new SalesCreditNoteItem;
        $match = new SalesInvoiceDeliveryMatch;

        $match->salesInvoiceItem()->associate($invoiceItem);
        $match->salesDeliveryItem()->associate($deliveryItem);
        $return->salesOrder()->associate($order);
        $return->salesInvoice()->associate($invoice);
        $returnItem->salesReturn()->associate($return);
        $returnItem->salesDeliveryItem()->associate($deliveryItem);
        $creditNote->salesInvoice()->associate($invoice);
        $creditNote->salesReturn()->associate($return);
        $creditItem->salesCreditNote()->associate($creditNote);
        $creditItem->salesInvoiceItem()->associate($invoiceItem);
        $creditItem->salesReturnItem()->associate($returnItem);

        $this->assertSame(3, $match->sales_invoice_item_id);
        $this->assertSame(4, $match->sales_delivery_item_id);
        $this->assertSame(1, $return->sales_order_id);
        $this->assertSame(2, $return->sales_invoice_id);
        $this->assertSame(5, $returnItem->sales_return_id);
        $this->assertSame(4, $returnItem->sales_delivery_item_id);
        $this->assertSame(2, $creditNote->sales_invoice_id);
        $this->assertSame(5, $creditNote->sales_return_id);
        $this->assertSame(7, $creditItem->sales_credit_note_id);
        $this->assertSame(3, $creditItem->sales_invoice_item_id);
        $this->assertSame(6, $creditItem->sales_return_item_id);
        $this->assertTrue($creditItem->salesReturnItem->is($returnItem));
        $creditItem->salesReturnItem()->dissociate();
        $this->assertNull($creditItem->sales_return_item_id);
        $this->assertNull($creditItem->salesReturnItem);
    }
}
