<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Schemas;

use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Sales\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesStockReservation;
use App\Modules\Sales\Services\SalesProfitability;
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
                RepeatableEntry::make('items')->label(__('Order lines'))->columnSpanFull()->extraAttributes(['class' => 'erp-document-evidence fox-master-detail-table'])
                    ->table(array_map(fn (string $label): TableColumn => TableColumn::make(__($label))->wrapHeader(),
                        ['Product', 'Variant', 'Quantity', 'Unit price', 'Line total', 'View Details']))->schema([
                            TextEntry::make('description')->formatStateUsing(fn ($state, $record) => view('components.commercial-product-cell', ['variant' => $record->variant, 'title' => $state]))->label(__('Product')),
                            View::make('components.variant-summary')->viewData(fn ($record): array => ['variant' => $record->variant]),
                            TextEntry::make('quantity')->label(__('Quantity'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Quantity'), 'value' => $state, 'kind' => 'quantity'])),
                            TextEntry::make('unit_price')->label(__('Unit selling price (EGP, excluding tax)'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Unit price'), 'value' => $state, 'kind' => 'precise'])),
                            TextEntry::make('line_total')->label(__('Line total')),
                            MasterDetail::entry(fn (): array => self::lineDetails()),
                        ]),
            ]),
            Group::make()->columns(['default' => 1, 'lg' => 12])->columnSpanFull()->schema([
                Section::make(__('Notes'))->icon('heroicon-o-pencil-square')->columnSpan(['default' => 1, 'lg' => 7])->schema([TextEntry::make('notes')->hiddenLabel()->placeholder(__('No notes'))]),
                Section::make(__('Financial totals'))->icon('heroicon-o-calculator')->columnSpan(['default' => 1, 'lg' => 5])->schema([
                    View::make('components.financial-summary')->viewData(fn (SalesOrder $record): array => ['commercial' => true, 'totals' => $record->only(['subtotal', 'discount_amount', 'tax_amount', 'total_amount'])]),
                    View::make('components.profitability-summary')->visible(fn (SalesOrder $record): bool => app(SalesProfitability::class)->canView(auth()->user(), (int) $record->location_id))
                        ->viewData(fn (SalesOrder $record): array => ['profitability' => self::profitability($record)['summary']]),
                ]),
            ]),
            Section::make(__('Stock reservations'))->schema([
                RepeatableEntry::make('reservations')->label(__('Reservation history'))
                    ->state(fn (SalesOrder $record) => $record->reservations()->with(['variant.product.images', 'variant.product.brand', 'variant.images', 'variant.size', 'variant.color'])->orderBy('variant_id')->get())
                    ->columns(4)->schema([
                        TextEntry::make('variant_label')->label(__('Product'))->state(fn (SalesStockReservation $record): string => $record->variant->displayName())->formatStateUsing(fn ($record) => view('components.commercial-product-cell', ['variant' => $record->variant]))->columnSpanFull(),
                        TextEntry::make('quantity_reserved')->label(__('Originally reserved')),
                        TextEntry::make('quantity_consumed')->label(__('Consumed quantity')),
                        TextEntry::make('quantity_released')->label(__('Released quantity')),
                        TextEntry::make('active_quantity')->label(__('Active reserved quantity'))->state(fn (SalesStockReservation $record): string => $record->activeQuantity()),
                    ]),
            ]),
        ])->columns(1);
    }

    private static function profitability(SalesOrder $order): array
    {
        abort_unless(app(SalesProfitability::class)->canView(auth()->user(), (int) $order->location_id), 403);
        $key = 'sales-profitability-order.'.$order->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, app(SalesProfitability::class)->order(auth()->user(), $order));
        }

        return request()->attributes->get($key);
    }

    private static function lineDetails(): array
    {
        return [
            Section::make(__('Product'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('description')->formatStateUsing(fn ($state, $record) => view('components.commercial-product-cell', ['variant' => $record->variant, 'title' => $state]))->label(__('Product')),
                View::make('components.variant-summary')->viewData(fn ($record): array => ['variant' => $record->variant]),
            ]),
            Section::make(__('Financial totals'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('quantity')->label(__('Quantity'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Quantity'), 'value' => $state, 'kind' => 'quantity'])),
                TextEntry::make('unit_price')->label(__('Unit selling price (EGP, excluding tax)'))->formatStateUsing(fn ($state) => view('components.commercial-line-value', ['label' => __('Unit price'), 'value' => $state, 'kind' => 'precise'])),
                TextEntry::make('discount_amount')->label(__('Discounts'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'discount', 'amount' => $state])),
                TextEntry::make('tax_amount')->label(__('Taxes'))->formatStateUsing(fn ($state, $record) => view('components.commercial-snapshot-cell', ['snapshot' => $record->commercial_snapshot, 'kind' => 'tax', 'amount' => $state])),
                Group::make()->schema([
                    TextEntry::make('line_total')->label(__('Line total (EGP)'))->formatStateUsing(fn ($state, $record) => view('components.commercial-line-value', ['label' => __('Line total'), 'value' => $state, 'emphasized' => true, 'subtotal' => $record->subtotal])),
                    View::make('components.profitability-line')->visible(fn ($record): bool => app(SalesProfitability::class)->canView(auth()->user(), (int) $record->salesOrder->location_id))
                        ->viewData(fn ($record): array => ['profitability' => self::profitability($record->salesOrder)['lines'][$record->id] ?? null]),
                ]),
            ]),
        ];
    }
}
