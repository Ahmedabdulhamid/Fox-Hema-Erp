<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Schemas;

use App\Models\QuantityFormatter;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\PurchaseReceiptItem;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class PurchaseReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('Goods Receipt'))->columns(3)->schema([
                TextEntry::make('receipt_number')->label(__('Receipt number')),
                TextEntry::make('purchaseOrder.order_number')->label(__('PO number')),
                TextEntry::make('purchaseOrder.supplier.name')->label(__('Supplier')),
                TextEntry::make('location.name')->label(__('Destination location')),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                TextEntry::make('received_at')->label(__('Received date'))->dateTime(),
                TextEntry::make('posted_at')->label(__('Validated at'))->dateTime(),
                TextEntry::make('postedBy.name')->label(__('Validated by')),
                TextEntry::make('backorderOf.receipt_number')->label(__('Backorder of')),
                TextEntry::make('total_purchase_value')->label(__('Total purchase value (EGP)'))->state(fn (PurchaseReceipt $record): string => (string) $record->items
                    ->reduce(fn (BigDecimal $total, PurchaseReceiptItem $item): BigDecimal => $total
                        ->plus(BigDecimal::of($item->quantity)->multipliedBy($item->unit_cost)->toScale(4, RoundingMode::HalfUp)), BigDecimal::zero())->toScale(4)),
            ]),
            RepeatableEntry::make('items')->label(__('Receipt lines'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])
                ->table(array_map(fn ($label) => TableColumn::make(__($label))->wrapHeader(),
                    ['Product variant', 'Ordered quantity', 'Expected quantity', 'Previously received quantity', 'Remaining quantity', 'Quantity to receive',
                        'Actual unit purchase cost (EGP)', 'Purchase value (EGP)', 'Costing method', 'Inventory valuation unit cost (EGP)', 'Inventory value (EGP)', 'Purchase-price variance (EGP)']))->schema([
                            TextEntry::make('variant_label')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->purchaseOrderItem->variant, 'title' => $state]))->label(__('Product variant'))->state(fn (PurchaseReceiptItem $record): string => $record->purchaseOrderItem->variant->displayName()),
                            TextEntry::make('purchaseOrderItem.quantity')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Ordered quantity')),
                            TextEntry::make('expected_quantity')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Expected quantity')),
                            TextEntry::make('previously_received')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Previously received quantity'))->state(function (PurchaseReceiptItem $record): string {
                                $total = app(PurchaseReceiptService::class)->received($record->purchaseOrder)[$record->purchase_order_item_id] ?? '0';

                                return (string) BigDecimal::of($total)->minus($record->purchaseReceipt->status === 'posted' ? $record->quantity : '0')->toScale(4);
                            }),
                            TextEntry::make('remaining')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Remaining quantity'))->state(fn (PurchaseReceiptItem $record): string => (string) BigDecimal::of($record->purchaseOrderItem->quantity)
                                ->minus(app(PurchaseReceiptService::class)->received($record->purchaseOrder)[$record->purchase_order_item_id] ?? '0')->toScale(4)),
                            TextEntry::make('quantity')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity to receive')),
                            TextEntry::make('unit_cost')->label(__('Actual unit purchase cost (EGP)')),
                            TextEntry::make('purchase_value')->label(__('Purchase value (EGP)'))->state(fn (PurchaseReceiptItem $record): string => (string) BigDecimal::of($record->quantity)->multipliedBy($record->unit_cost)->toScale(4, RoundingMode::HalfUp)),
                            TextEntry::make('stockMovement.inventoryCostEntry.method')->label(__('Costing method')),
                            TextEntry::make('stockMovement.valuation_unit_cost')->label(__('Inventory valuation unit cost (EGP)')),
                            TextEntry::make('stockMovement.inventoryCostEntry.total_cost')->label(__('Inventory value (EGP)')),
                            TextEntry::make('stockMovement.inventoryCostEntry.purchase_price_variance')->label(__('Purchase-price variance (EGP)')),
                        ]),
            TextEntry::make('notes')->label(__('Notes')),
        ])->columns(1);
    }
}
