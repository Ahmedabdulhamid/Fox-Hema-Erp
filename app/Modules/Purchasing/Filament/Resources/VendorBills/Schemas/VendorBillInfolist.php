<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills\Schemas;

use App\Models\MoneyFormatter;
use App\Models\QuantityFormatter;
use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\SupplierFinancialBalances;
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
                TextEntry::make('settlement_terms')->label(__('Commercial settlement terms'))->placeholder(__('Unspecified'))->formatStateUsing(fn ($state): string => __(ucfirst($state))),
                TextEntry::make('planned_immediate_amount')->label(__('Planned immediate settlement'))->placeholder(__('Unspecified')),
                TextEntry::make('payment_status')->label(__('Payment status'))
                    ->visible(fn (): bool => VendorPaymentResource::canViewAny())
                    ->state(function (VendorBill $record): string {
                        if ($record->status !== 'posted') {
                            return __('Not posted');
                        }
                        $amounts = app(SupplierFinancialBalances::class)->bill(auth()->user(), $record);
                        $status = BigDecimal::of($amounts['outstanding'])->isZero() ? 'Settled' : (BigDecimal::of($amounts['paid'])->isZero() ? 'Unpaid' : 'Partially paid');

                        return __($status).' | '.__('Paid').': '.MoneyFormatter::format($amounts['paid']).' | '.__('Outstanding').': '.MoneyFormatter::format($amounts['outstanding']);
                    }),
                TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
                TextEntry::make('notes')->label(__('Notes'))->columnSpanFull(),
            ]),
            Section::make(__('Bill lines'))->schema([
                RepeatableEntry::make('items')->key('bill-line-table')->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence fox-master-detail-table'])
                    ->table(array_map(fn (string $label): TableColumn => TableColumn::make(__($label))->wrapHeader(),
                        ['Product', 'Variant', 'Quantity', 'Unit price', 'Line total', 'View Details']))->schema([
                            TextEntry::make('description')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state])),
                            View::make('components.variant-summary')->viewData(fn ($record): array => ['variant' => $record->variant]),
                            TextEntry::make('quantity')->formatStateUsing(fn ($state) => QuantityFormatter::format($state)),
                            TextEntry::make('unit_price')->formatStateUsing(fn ($state) => MoneyFormatter::precise($state)),
                            TextEntry::make('line_total')->formatStateUsing(fn ($state) => MoneyFormatter::format($state)),
                            MasterDetail::entry(function ($record): array {
                                $record->loadMissing(['receiptMatches.purchaseReceiptItem.purchaseReceipt', 'receiptMatches.purchaseReceiptItem.purchaseOrderItem', 'receiptMatches.vendorBillItem']);

                                return self::lineDetails();
                            }),
                        ]),
            ]),

        ])->columns(1);
    }

    private static function lineDetails(): array
    {
        return [
            Section::make(__('Product'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('description')->label(__('Product / Variant'))->columnSpanFull(),
                TextEntry::make('variant.sku')->label(__('SKU')),
                TextEntry::make('variant.size.name')->label(__('Size')),
                TextEntry::make('variant.color.name')->label(__('Color')),
            ]),
            Section::make(__('Financial totals'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('quantity')->label(__('Quantity to bill')),
                TextEntry::make('unit_price')->label(__('Invoice unit price')),
                TextEntry::make('discount_amount')->label(__('Invoice discount')),
                TextEntry::make('tax_amount')->label(__('Invoice tax')),
                TextEntry::make('line_total')->label(__('Line total')),
            ]),
            Section::make(__('Receipt allocations'))->schema([
                RepeatableEntry::make('receiptMatches')->hiddenLabel()->placeholder(__('No records'))->columns(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('purchaseReceiptItem.purchaseReceipt.receipt_number')->label(__('Goods Receipt')),
                    TextEntry::make('matched_quantity')->label(__('Matched quantity')),
                    TextEntry::make('purchaseReceiptItem.unit_cost')->label(__('Receipt purchase cost')),
                    TextEntry::make('purchaseReceiptItem.purchaseOrderItem.unit_price')->label(__('PO unit price')),
                    TextEntry::make('price_difference')->label(__('Price difference requires review'))->state(fn ($record): string => (string) BigDecimal::of($record->vendorBillItem->unit_price)->minus($record->purchaseReceiptItem->unit_cost))
                        ->color(fn ($record): string => BigDecimal::of($record->vendorBillItem->unit_price)->isEqualTo($record->purchaseReceiptItem->unit_cost) ? 'gray' : 'warning'),
                ]),
            ]),
        ];
    }
}
