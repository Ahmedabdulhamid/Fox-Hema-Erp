<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Schemas;

use App\Models\MoneyFormatter;
use App\Models\QuantityFormatter;
use Brick\Math\BigDecimal;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class VendorBillInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('Supplier invoice'))->columns(3)->schema([
                TextEntry::make('display_reference')->label(__('documents.erp_number')),
                TextEntry::make('bill_number')->label(__('Supplier invoice number')),
                TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('purchaseOrder.order_number')->label(__('Purchase Order')),
                TextEntry::make('purchaseOrder.location.name')->label(__('Location')),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state): string => __(ucfirst($state))),
                TextEntry::make('bill_date')->label(__('Bill date'))->date(), TextEntry::make('due_date')->label(__('Due date'))->date(),
                TextEntry::make('total_amount')->label(__('Bill total'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
                TextEntry::make('notes')->label(__('Notes'))->columnSpanFull(),
            ]),
            Section::make(__('Bill lines'))->schema([
                RepeatableEntry::make('items')->key('bill-line-table')->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])
                    ->table(array_map(fn ($label) => TableColumn::make(__($label))->wrapHeader(),
                        ['Product / Variant', 'SKU', 'Size', 'Color', 'Quantity to bill', 'Invoice unit price', 'Invoice discount', 'Invoice tax', 'Line total']))->schema([
                            TextEntry::make('description')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state])), TextEntry::make('variant.sku'), TextEntry::make('variant.size.name'), TextEntry::make('variant.color.name'),
                            TextEntry::make('quantity')->formatStateUsing(fn ($state) => QuantityFormatter::format($state)),
                            TextEntry::make('unit_price')->formatStateUsing(fn ($state) => MoneyFormatter::format($state)),
                            TextEntry::make('discount_amount')->formatStateUsing(fn ($state) => MoneyFormatter::format($state)),
                            TextEntry::make('tax_amount')->formatStateUsing(fn ($state) => MoneyFormatter::format($state)),
                            TextEntry::make('line_total')->formatStateUsing(fn ($state) => MoneyFormatter::format($state)),
                        ]),
            ]),
            Section::make(__('documents.evidence'))->collapsible()->collapsed()->schema([
                RepeatableEntry::make('items')->key('bill-line-evidence')->label(__('Bill lines'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence'])->columns(['default' => 1, 'md' => 3])->schema([
                    TextEntry::make('description')->label(__('Product / Variant'))->columnSpanFull(),
                    TextEntry::make('quantity')->label(__('Quantity to bill'))->formatStateUsing(fn ($state): string => QuantityFormatter::format($state)),
                    TextEntry::make('unit_price')->label(__('Invoice unit price'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                    TextEntry::make('discount_amount')->label(__('Invoice discount'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                    TextEntry::make('tax_amount')->label(__('Invoice tax'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                    TextEntry::make('line_total')->label(__('Line total'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                    RepeatableEntry::make('receiptMatches')->label(__('Receipt allocations'))->columnSpanFull()->columns(3)->schema([
                        TextEntry::make('purchaseReceiptItem.purchaseReceipt.receipt_number')->label(__('Goods Receipt')),
                        TextEntry::make('matched_quantity')->label(__('Matched quantity'))->formatStateUsing(fn ($state): string => QuantityFormatter::format($state)),
                        TextEntry::make('purchaseReceiptItem.unit_cost')->label(__('Receipt purchase cost'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                        TextEntry::make('purchaseReceiptItem.purchaseOrderItem.unit_price')->label(__('PO unit price'))->formatStateUsing(fn ($state): string => MoneyFormatter::format($state)),
                        TextEntry::make('price_difference')->label(__('Price difference requires review'))->state(fn ($record): string => (string) BigDecimal::of($record->vendorBillItem->unit_price)->minus($record->purchaseReceiptItem->unit_cost))
                            ->suffix(' EGP')->color(fn ($record): string => BigDecimal::of($record->vendorBillItem->unit_price)->isEqualTo($record->purchaseReceiptItem->unit_cost) ? 'gray' : 'warning'),
                    ]),
                ]),
            ]),
        ])->columns(1);
    }
}
