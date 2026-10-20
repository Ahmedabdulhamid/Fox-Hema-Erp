<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Schemas;

use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
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

class SalesDeliveryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Delivery information'))->columns(2)->schema([
                Select::make('sales_order_id')->label(__('Sales Order'))->required()->searchable()->live()
                    ->options(fn (): array => app(DocumentSelectors::class)->orders(auth()->user(), 'delivery'))
                    ->getSearchResultsUsing(fn (string $search): array => app(DocumentSelectors::class)->orders(auth()->user(), 'delivery', $search))
                    ->getOptionLabelUsing(fn ($value): ?string => app(DocumentSelectors::class)->selectedLabel(auth()->user(), 'delivery', $value))
                    ->formatStateUsing(fn ($state): ?string => app(DocumentSelectors::class)->orderValue(auth()->user(), 'delivery', $state))
                    ->disabledOn('edit')->dehydrated()
                    ->afterStateUpdated(fn (Set $set) => $set('items', [])),
                DateTimePicker::make('delivered_at')->label(__('Delivery date'))->seconds(false)->format('Y-m-d H:i:s'),
                Text::make(fn (Get $get): string => __('Branch').': '.(app(DocumentSelectors::class)->selectedOrder(auth()->user(), 'delivery', $get('sales_order_id'))?->location?->name ?? '—')),
            ]),
            Section::make(__('Delivery items'))->schema([
                Text::make(__('Draft deliveries do not change stock, reservations or fulfillment.')),
                Repeater::make('items')->label(__('Delivery items'))->defaultItems(1)->minItems(1)->maxItems(200)
                    ->reorderable(false)->columns(4)->schema([
                        Hidden::make('id'),
                        ...CommercialSelectors::sourceFilters('delivery', 'sales_order_item_id'),
                        Select::make('sales_order_item_id')->label(__('Order line'))->required()->searchable()->live()->columnSpanFull()
                            ->options(fn (Get $get): array => app(DocumentSelectors::class)->lines(auth()->user(), 'delivery', $get('../../sales_order_id'), '', $get('brand_id'), $get('product_id')))
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => app(DocumentSelectors::class)->lines(auth()->user(), 'delivery', $get('../../sales_order_id'), $search, $get('brand_id'), $get('product_id')))
                            ->getOptionLabelUsing(fn (Get $get, $value): ?string => app(DocumentSelectors::class)->selectedLineLabel(auth()->user(), 'delivery', $get('../../sales_order_id'), $value))
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->afterStateUpdated(fn (Set $set) => $set('id', null)),
                        Text::make(fn (Get $get): string => self::detail($get, 'ordered', 'Ordered quantity')),
                        Text::make(fn (Get $get): string => self::detail($get, 'delivered', 'Already delivered quantity')),
                        Text::make(fn (Get $get): string => self::detail($get, 'remaining', 'Remaining quantity')),
                        ProductLinePreview::make(self::class, 'sales_order_item_id', function (Get $get, array $ids): Collection {
                            $order = app(DocumentSelectors::class)->selectedOrder(auth()->user(), 'delivery', $get('../../sales_order_id'));
                            if ($order === null) {
                                return collect();
                            }

                            return ProductLinePreview::byKeys($order->items()->getQuery(), $ids)->with(['variant.images', 'variant.product.images'])->get()->keyBy('id');
                        }, 'variant'),
                        TextInput::make('quantity')->label(__('Delivery draft quantity'))->required()->default('1')
                            ->type('number')->step('0.0001')->minValue('0.0001')->inputMode('decimal'),
                    ]),
            ]),
            Section::make(__('Notes'))->schema([Textarea::make('notes')->label(__('Notes'))->maxLength(16000)]),
        ])->columns(1);
    }

    private static function detail(Get $get, string $key, string $label): string
    {
        $details = app(DocumentSelectors::class)->lineDetail(auth()->user(), 'delivery', $get('../../sales_order_id'), $get('sales_order_item_id'));

        return __($label).': '.($details[$key] ?? '—');
    }
}
