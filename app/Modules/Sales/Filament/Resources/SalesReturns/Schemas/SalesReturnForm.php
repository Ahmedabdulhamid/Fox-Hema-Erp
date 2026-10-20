<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Schemas;

use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Sales\Models\SalesDeliveryItem;
use Filament\Forms\Components\DatePicker;
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

class SalesReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('sales_invoice_id'),
            Section::make(__('returns.return'))->columns(2)->schema([
                Select::make('sales_order_id')->label(__('returns.order'))->required()->searchable()->live()
                    ->options(fn (): array => app(DocumentSelectors::class)->orders(auth()->user(), 'return'))
                    ->getSearchResultsUsing(fn (string $search): array => app(DocumentSelectors::class)->orders(auth()->user(), 'return', $search))
                    ->getOptionLabelUsing(fn ($value): ?string => app(DocumentSelectors::class)->selectedLabel(auth()->user(), 'return', $value))
                    ->formatStateUsing(fn ($state): ?string => app(DocumentSelectors::class)->orderValue(auth()->user(), 'return', $state))
                    ->disabledOn('edit')->dehydrated()->afterStateUpdated(fn (Set $set) => $set('items', [])),
                DatePicker::make('return_date')->label(__('returns.return_date'))->required()->default(today())->format('Y-m-d'),
                TextInput::make('reason')->label(__('returns.reason'))->maxLength(255)->columnSpanFull(),
            ]),
            Section::make(__('returns.items'))->schema([
                Text::make(__('returns.allocation')),
                Repeater::make('items')->label(__('returns.items'))->minItems(1)->maxItems(200)->defaultItems(1)->columns(4)->reorderable(false)->schema([
                    Hidden::make('id'),
                    Hidden::make('sales_invoice_item_id'),
                    ...CommercialSelectors::sourceFilters('return', 'sales_delivery_item_id'),
                    Select::make('sales_delivery_item_id')->label(__('returns.delivery_line'))->required()->searchable()->live()->columnSpanFull()
                        ->disabled(fn (Get $get): bool => filled($get('id')))->dehydrated()
                        ->options(fn (Get $get): array => app(DocumentSelectors::class)->lines(auth()->user(), 'return', $get('../../sales_order_id'), '', $get('brand_id'), $get('product_id')))
                        ->getSearchResultsUsing(fn (Get $get, string $search): array => app(DocumentSelectors::class)->lines(auth()->user(), 'return', $get('../../sales_order_id'), $search, $get('brand_id'), $get('product_id')))
                        ->getOptionLabelUsing(fn (Get $get, $value): ?string => app(DocumentSelectors::class)->selectedLineLabel(auth()->user(), 'return', $get('../../sales_order_id'), $value)),
                    Text::make(fn (Get $get): string => self::details($get)),
                    ProductLinePreview::make(self::class, 'sales_delivery_item_id', function (Get $get, array $ids): Collection {
                        $order = app(DocumentSelectors::class)->selectedOrder(auth()->user(), 'return', $get('../../sales_order_id'));
                        if ($order === null) {
                            return collect();
                        }

                        return ProductLinePreview::byKeys(SalesDeliveryItem::where('sales_order_id', $order->id)->whereHas('salesDelivery', fn ($query) => $query->where('status', 'posted')), $ids)->with(['salesOrderItem.variant.images', 'salesOrderItem.variant.product.images'])->get()->keyBy('id');
                    }, 'salesOrderItem.variant'),
                    TextInput::make('quantity')->label(__('returns.quantity'))->required()->default('1')->type('number')->step('0.0001')->minValue('0.0001')->inputMode('decimal'),
                    Select::make('disposition')->label(__('returns.disposition'))->required()->default('restock')->options([
                        'restock' => __('returns.restock'), 'damaged' => __('returns.damaged'), 'scrap' => __('returns.scrap'),
                    ]),
                    Textarea::make('notes')->label(__('returns.notes'))->maxLength(5000),
                ]),
            ]),
            Textarea::make('notes')->label(__('returns.notes'))->maxLength(16000),
        ])->columns(1);
    }

    private static function details(Get $get): string
    {
        $details = app(DocumentSelectors::class)->lineDetail(auth()->user(), 'return', $get('../../sales_order_id'), $get('sales_delivery_item_id'));

        return collect(['delivered', 'posted', 'claimed', 'remaining'])->map(fn ($key) => __('returns.'.$key).': '.($details[$key] ?? '—'))->implode(' | ');
    }
}
