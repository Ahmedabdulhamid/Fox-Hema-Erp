<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes\Schemas;

use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Models\Product;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SalesCreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('credits.document_information'))->icon('heroicon-o-document-text')->columns(['default' => 1, 'lg' => 3])->schema([
                Select::make('sales_return_id')->label(__('credits.return'))->required()->searchable()->live()->disabledOn('edit')->dehydrated()
                    ->options(fn (): array => app(SalesCreditNoteService::class)->returns(auth()->user())->orderByDesc('id')->limit(200)->get()->pluck('display_reference', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search): array => app(SalesCreditNoteService::class)->returns(auth()->user())->where('return_number', 'like', '%'.$search.'%')->orderByDesc('id')->limit(50)->get()->pluck('display_reference', 'id')->all())
                    ->getOptionLabelUsing(fn ($value): ?string => app(SalesCreditNoteService::class)->returns(auth()->user())->find($value)?->display_reference)
                    ->helperText(__('credits.eligibility'))->afterStateUpdated(function (Set $set): void {
                        $set('sales_invoice_id', null);
                        $set('items', []);
                    }),
                Select::make('sales_invoice_id')->label(__('credits.invoice'))->required()->searchable()->live()->disabledOn('edit')->dehydrated()
                    ->options(fn (Get $get): array => collect(app(SalesCreditNoteService::class)->details(auth()->user(), $get('sales_return_id')))->pluck('invoice_number', 'sales_invoice_id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('items', [])),
                DatePicker::make('credit_date')->label(__('invoices.issue_date'))->required()->default(today())->format('Y-m-d'),
                View::make('components.credit-note-source')->viewData(function (Get $get): array {
                    $source = app(SalesCreditNoteService::class)->returns(auth()->user())
                        ->with(['location', 'salesOrder'])->find($get('sales_return_id'));

                    return [
                        'source' => $source,
                        'invoice' => $source?->salesOrder?->invoices()->where('location_id', $source->location_id)->where('status', 'posted')->find($get('sales_invoice_id')),
                    ];
                })->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make(__('credits.returned_products'))->icon('heroicon-o-cube')->schema([
                View::make('components.credit-note-products')->viewData(function (Get $get): array {
                    $rows = app(SalesCreditNoteService::class)->details(auth()->user(), $get('sales_return_id'), $get('sales_invoice_id'));

                    return [
                        'rows' => $rows,
                        'invoiceItems' => SalesInvoiceItem::query()->whereIn('id', array_column($rows, 'invoice_item_id'))
                            ->with(['variant.images', 'variant.product.images', 'variant.product.brand', 'variant.color', 'variant.size'])->get()->keyBy('id'),
                    ];
                })->columnSpanFull(),
                Repeater::make('items')->label(__('invoices.items'))->defaultItems(1)->minItems(1)->maxItems(200)->reorderable(false)->addActionLabel(__('credits.add_line'))->columns(['default' => 1, 'lg' => 3])->live()->schema([
                    CommercialSelectors::brand(fn (Get $get) => self::sourceProducts($get))
                        ->afterStateHydrated(fn (Get $get, Set $set) => $set('brand_id', self::selectedSource($get)['brand_id'] ?? null))
                        ->afterStateUpdated(function (Set $set): void {
                            $set('product_id', null);
                            $set('sales_return_posted_allocation_id', null);
                        }),
                    CommercialSelectors::brandPreview(),
                    Select::make('product_id')->label(__('Product'))->searchable()->live()->dehydrated(false)
                        ->options(fn (Get $get): array => self::productOptions($get))
                        ->getSearchResultsUsing(fn (Get $get, string $search): array => self::productOptions($get, $search))
                        ->getOptionLabelUsing(fn (Get $get, $value): ?string => self::sourceProducts($get)->whereKey($value)->value('name'))
                        ->afterStateHydrated(fn (Get $get, Set $set) => $set('product_id', self::selectedSource($get)['product_id'] ?? null))
                        ->afterStateUpdated(fn (Set $set) => $set('sales_return_posted_allocation_id', null)),
                    Select::make('sales_return_posted_allocation_id')->label(__('credits.allocation'))->required()->searchable()->live()
                        ->options(fn (Get $get): array => collect(app(SalesCreditNoteService::class)->details(auth()->user(), $get('../../sales_return_id'), $get('../../sales_invoice_id')))
                            ->filter(fn ($row): bool => (blank($get('brand_id')) || (string) $row['brand_id'] === (string) $get('brand_id')) && (blank($get('product_id')) || (string) $row['product_id'] === (string) $get('product_id')))
                            ->mapWithKeys(fn ($row, $id) => [$id => $row['description'].' | '.__('credits.remaining').': '.$row['remaining'].' | '.__('credits.total').': '.$row['remaining_amount']])->all())
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems()->columnSpan(['default' => 1, 'lg' => 2]),
                    TextInput::make('quantity')->label(__('invoices.quantity'))->required()->type('number')->step('0.0001')->minValue('0.0001')->default('1')->live(onBlur: true),
                    View::make('components.credit-note-line')->viewData(function (Get $get): array {
                        $row = app(SalesCreditNoteService::class)->details(auth()->user(), $get('../../sales_return_id'), $get('../../sales_invoice_id'))[$get('sales_return_posted_allocation_id')] ?? null;

                        return ['row' => $row];
                    })->columnSpanFull(),
                ])->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make(__('credits.preview'))->icon('heroicon-o-calculator')->columnSpan(['default' => 1, 'lg' => 1])->schema([
                View::make('components.credit-note-summary')->viewData(function (Get $get): array {
                    try {
                        $totals = app(SalesCreditNoteService::class)->preview(auth()->user(), [
                            'sales_return_id' => $get('sales_return_id'), 'sales_invoice_id' => $get('sales_invoice_id'), 'credit_date' => $get('credit_date'), 'items' => array_values($get('items') ?? []),
                        ]);

                        return ['totals' => $totals];
                    } catch (ValidationException $exception) {
                        return ['totals' => null];
                    }
                })->columnSpanFull(),
            ]),
            Section::make(__('credits.additional_notes'))->icon('heroicon-o-chat-bubble-left-right')->columnSpan(['default' => 1, 'lg' => 2])->schema([
                Textarea::make('reason')->label(__('credits.reason'))->maxLength(16000)->rows(3)->columnSpanFull(),
                Text::make(__('credits.refund_notice'))->columnSpanFull(),
            ]),
        ])->columns(['default' => 1, 'lg' => 3]);
    }

    private static function sourceProducts(Get $get): Builder
    {
        $rows = app(SalesCreditNoteService::class)->details(auth()->user(), $get('../../sales_return_id'), $get('../../sales_invoice_id'));

        return Product::whereIn('id', array_column($rows, 'product_id'));
    }

    private static function productOptions(Get $get, string $search = ''): array
    {
        return self::sourceProducts($get)->when(filled($get('brand_id')), fn ($query) => $query->where('brand_id', $get('brand_id')))
            ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }

    private static function selectedSource(Get $get): array
    {
        return app(SalesCreditNoteService::class)->details(auth()->user(), $get('../../sales_return_id'), $get('../../sales_invoice_id'))[$get('sales_return_posted_allocation_id')] ?? [];
    }
}
