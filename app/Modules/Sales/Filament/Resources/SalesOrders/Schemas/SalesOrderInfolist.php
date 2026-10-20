<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Schemas;

use App\Modules\Sales\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesStockReservation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SalesOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            Section::make(__('Sales Order'))->columns(3)->schema([
                TextEntry::make('order_number')->label(__('Order Number'))->copyable(),
                TextEntry::make('location.name')->label(__('Branch')),
                TextEntry::make('ordered_at')->label(__('Order date'))->dateTime(),
                TextEntry::make('customer_name')->label(__('Customer'))->placeholder(__('Walk-in customer')),
                TextEntry::make('customer_phone')->label(__('Customer phone')),
                TextEntry::make('createdBy.name')->label(__('Created By')),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => SalesOrdersTable::statuses()[$state] ?? $state),
                TextEntry::make('fulfillment_status')->label(__('Fulfillment Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                TextEntry::make('confirmedBy.name')->label(__('Confirmed by')),
                TextEntry::make('confirmed_at')->label(__('Confirmed at'))->dateTime(),
                TextEntry::make('cancelledBy.name')->label(__('Cancelled by')),
                TextEntry::make('cancelled_at')->label(__('Cancelled at'))->dateTime(),
            ]),
            Section::make(__('Sales order items'))->schema([
                RepeatableEntry::make('items')->label(__('Order lines'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence fox-commercial-lines fox-commercial-saved'])
                    ->table(array_map(fn ($label) => TableColumn::make(__($label))->wrapHeader(),
                        ['Brand / Product', 'Variant', 'Quantity', 'Unit price', 'Discounts', 'Taxes', 'Line total']))->schema([
                            TextEntry::make('description')->formatStateUsing(fn ($state, $record) => view('components.commercial-product-cell', ['variant' => $record->variant, 'title' => $state]))->label(__('Product')),
                            TextEntry::make('variant_details')->label(__('Variant'))->state(fn ($record): string => implode(' ? ', array_filter([$record->variant?->size?->name, $record->variant?->color?->name, $record->variant?->sku]))),
                            TextEntry::make('quantity')->label(__('Quantity'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Quantity'), 'value' => $state])),
                            TextEntry::make('unit_price')->label(__('Unit selling price (EGP, excluding tax)'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Unit price'), 'value' => $state])),
                            TextEntry::make('discount_amount')->label(__('Discounts'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'discount', 'amount' => $state])),
                            TextEntry::make('tax_amount')->label(__('Taxes'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'tax', 'amount' => $state])),
                            TextEntry::make('line_total')->label(__('Line total (EGP)'))->formatStateUsing(fn ($state, $record) => view('components.commercial-line-value', ['label' => __('Line total'), 'value' => $state, 'emphasized' => true, 'subtotal' => $record->subtotal])),
                        ]),
            ]),
            Group::make()->columns(['default' => 1, 'lg' => 12])->columnSpanFull()->schema([
                Section::make(__('Notes'))->icon('heroicon-o-pencil-square')->columnSpan(['default' => 1, 'lg' => 7])->schema([TextEntry::make('notes')->hiddenLabel()->placeholder(__('No notes'))]),
                Section::make(__('Financial totals'))->icon('heroicon-o-calculator')->columnSpan(['default' => 1, 'lg' => 5])->schema([
                    View::make('components.financial-summary')->viewData(fn (SalesOrder $record): array => ['commercial' => true, 'totals' => $record->only(['subtotal', 'discount_amount', 'tax_amount', 'total_amount'])]),
                ]),
            ]),
            Section::make(__('Stock reservations'))->schema([
                RepeatableEntry::make('reservations')->label(__('Reservation history'))
                    ->state(fn (SalesOrder $record) => $record->reservations()->with(['variant.product', 'variant.size', 'variant.color'])->orderBy('variant_id')->get())
                    ->columns(4)->schema([
                        TextEntry::make('variant_label')->label(__('Product'))->state(fn (SalesStockReservation $record): string => $record->variant->displayName())->columnSpanFull(),
                        TextEntry::make('quantity_reserved')->label(__('Originally reserved')),
                        TextEntry::make('quantity_consumed')->label(__('Consumed quantity')),
                        TextEntry::make('quantity_released')->label(__('Released quantity')),
                        TextEntry::make('active_quantity')->label(__('Active reserved quantity'))->state(fn (SalesStockReservation $record): string => $record->activeQuantity()),
                    ]),
            ]),
        ])->columns(1);
    }
}
