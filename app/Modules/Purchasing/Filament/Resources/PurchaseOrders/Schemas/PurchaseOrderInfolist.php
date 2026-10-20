<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\QuantityFormatter;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('Purchase Order'))->columns(3)->schema([
                TextEntry::make('order_number')->label(__('PO number')), TextEntry::make('supplier.name')->label(__('Supplier')),
                TextEntry::make('location.name')->label(__('Location')), TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('order_date')->label(__('Order date'))->date(), TextEntry::make('expected_date')->label(__('Expected delivery date'))->date(),
                TextEntry::make('confirmedBy.name')->label(__('Confirmed by')), TextEntry::make('confirmed_at')->label(__('Confirmed at'))->dateTime(),
            ]),
            Section::make(__('Ordered items'))->description(__('These are ordered quantities and agreed prices; this document does not prove goods receipt.'))->schema([
                RepeatableEntry::make('items')->label(__('Order lines'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence fox-commercial-lines fox-commercial-saved'])
                    ->table(array_map(fn ($label) => TableColumn::make(__($label))->wrapHeader(),
                        ['Brand / Product', 'Variant', 'Quantity', 'Unit price', 'Discounts', 'Taxes', 'Line total']))->schema([
                            TextEntry::make('variant_label')->formatStateUsing(fn ($state, $record) => view('components.commercial-product-cell', ['variant' => $record->variant, 'title' => $state]))->label(__('Product'))->state(fn ($record): string => $record->variant->displayName()),
                            TextEntry::make('variant_details')->label(__('Variant'))->state(fn ($record): string => implode(' ? ', array_filter([$record->variant?->size?->name, $record->variant?->color?->name, $record->variant?->sku]))),
                            TextEntry::make('quantity')->formatStateUsing(fn (?string $state) => view('components.commercial-line-value', ['label' => __('Quantity'), 'value' => QuantityFormatter::format($state)]))->label(__('Ordered quantity')),
                            TextEntry::make('unit_price')->label(__('Unit purchase price (EGP)'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Unit price'), 'value' => $state])),
                            TextEntry::make('discount_amount')->label(__('Discounts'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'discount', 'amount' => $state])),
                            TextEntry::make('tax_amount')->label(__('Taxes'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'tax', 'amount' => $state])),
                            TextEntry::make('line_total')->label(__('Line total (EGP)'))->formatStateUsing(fn ($state, $record) => view('components.commercial-line-value', ['label' => __('Line total'), 'value' => $state, 'emphasized' => true, 'subtotal' => $record->subtotal])),
                        ]),
            ]),
            Group::make()->columns(['default' => 1, 'lg' => 12])->columnSpanFull()->schema([
                Section::make(__('Notes'))->icon('heroicon-o-pencil-square')->columnSpan(['default' => 1, 'lg' => 7])->schema([TextEntry::make('notes')->hiddenLabel()->placeholder(__('No notes'))]),
                Section::make(__('Financial totals'))->icon('heroicon-o-calculator')->columnSpan(['default' => 1, 'lg' => 5])->schema([
                    View::make('components.financial-summary')->viewData(fn (PurchaseOrder $record): array => ['commercial' => true, 'totals' => $record->only(['subtotal', 'discount_amount', 'tax_amount', 'total_amount'])]),
                ]),
            ]),
            Section::make(__('Goods Receipts'))->columns(4)
                ->visible(fn (PurchaseOrder $record): bool => app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::RECEIPTS_VIEW, (int) $record->location_id))
                ->schema([
                    TextEntry::make('ordered_qty')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Ordered quantity'))->state(fn (PurchaseOrder $record): string => app(PurchaseReceiptService::class)->summary($record)['ordered']),
                    TextEntry::make('received_qty')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Posted received quantity'))->state(fn (PurchaseOrder $record): string => app(PurchaseReceiptService::class)->summary($record)['received']),
                    TextEntry::make('remaining_qty')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Remaining quantity'))->state(fn (PurchaseOrder $record): string => app(PurchaseReceiptService::class)->summary($record)['remaining']),
                    TextEntry::make('receipt_count')->label(__('Receipt count'))->state(fn (PurchaseOrder $record): int => app(PurchaseReceiptService::class)->summary($record)['receipt_count']),
                    RepeatableEntry::make('receipts')->label(__('Goods Receipts'))->columnSpanFull()->columns(2)->schema([
                        TextEntry::make('receipt_number')->label(__('Receipt number'))->url(fn ($record): string => PurchaseReceiptResource::getUrl('view', ['record' => $record])),
                        TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                    ]),
                ]),
        ])->columns(1);
    }
}
