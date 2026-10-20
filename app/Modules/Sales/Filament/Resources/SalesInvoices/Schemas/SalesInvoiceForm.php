<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Schemas;

use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Sales\Models\SalesDeliveryItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class SalesInvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('invoices.invoice'))->columns(3)->schema([
                Select::make('sales_order_id')->label(__('invoices.order'))->required()->searchable()->preload()->live()
                    ->options(fn (): array => app(DocumentSelectors::class)->orders(auth()->user(), 'invoice'))
                    ->getSearchResultsUsing(fn (string $search): array => app(DocumentSelectors::class)->orders(auth()->user(), 'invoice', $search))
                    ->noOptionsMessage(__('invoices.no_eligible_orders'))
                    ->noSearchResultsMessage(__('invoices.no_matching_orders'))
                    ->helperText(__('invoices.order_eligibility_help'))
                    ->getOptionLabelUsing(fn ($value): ?string => app(DocumentSelectors::class)->selectedLabel(auth()->user(), 'invoice', $value))
                    ->formatStateUsing(fn ($state): ?string => app(DocumentSelectors::class)->orderValue(auth()->user(), 'invoice', $state))
                    ->disabledOn('edit')->dehydrated()->afterStateUpdated(fn (Set $set) => $set('items', [])),
                DatePicker::make('invoice_date')->label(__('invoices.issue_date'))->required()->default(today())->format('Y-m-d'),
                DatePicker::make('due_date')->label(__('invoices.due_date'))->format('Y-m-d')->afterOrEqual('invoice_date'),
            ]),
            Section::make(__('invoices.items'))->schema([
                Text::make(__('invoices.allocation')),
                Repeater::make('items')->label(__('invoices.items'))->defaultItems(1)->minItems(1)->maxItems(200)
                    ->reorderable(false)->columns(4)->schema([
                        ...CommercialSelectors::sourceFilters('invoice', 'sales_delivery_item_id'),
                        Select::make('sales_delivery_item_id')->label(__('invoices.delivery_line'))->required()->searchable()->live()->columnSpanFull()
                            ->options(fn (Get $get): array => app(DocumentSelectors::class)->lines(auth()->user(), 'invoice', $get('../../sales_order_id'), '', $get('brand_id'), $get('product_id')))
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => app(DocumentSelectors::class)->lines(auth()->user(), 'invoice', $get('../../sales_order_id'), $search, $get('brand_id'), $get('product_id')))
                            ->getOptionLabelUsing(fn (Get $get, $value): ?string => app(DocumentSelectors::class)->selectedLineLabel(auth()->user(), 'invoice', $get('../../sales_order_id'), $value))
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        Text::make(fn (Get $get): string => self::detail($get, 'delivered')),
                        Text::make(fn (Get $get): string => self::detail($get, 'invoiced')),
                        Text::make(fn (Get $get): string => self::detail($get, 'remaining')),
                        ProductLinePreview::make(self::class, 'sales_delivery_item_id', function (Get $get, array $ids): Collection {
                            $order = app(DocumentSelectors::class)->selectedOrder(auth()->user(), 'invoice', $get('../../sales_order_id'));
                            if ($order === null) {
                                return collect();
                            }

                            return ProductLinePreview::byKeys(SalesDeliveryItem::where('sales_order_id', $order->id)->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted')), $ids)->with(['salesOrderItem.variant.images', 'salesOrderItem.variant.product.images'])->get()->keyBy('id');
                        }, 'salesOrderItem.variant'),
                        TextInput::make('quantity')->label(__('invoices.quantity'))->required()->default('1')
                            ->type('number')->step('0.0001')->minValue('0.0001')->inputMode('decimal'),
                    ]),
            ]),
            Textarea::make('notes')->label(__('invoices.notes'))->maxLength(16000),
        ])->columns(1);
    }

    private static function detail(Get $get, string $key): string
    {
        $details = app(DocumentSelectors::class)->lineDetail(auth()->user(), 'invoice', $get('../../sales_order_id'), $get('sales_delivery_item_id'));

        return __('invoices.'.$key).': '.($details[$key] ?? '—');
    }
}
