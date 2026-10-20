<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Schemas;

use App\Models\QuantityFormatter;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Sales\Services\SalesProductSelection;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Order information'))->icon('heroicon-o-clipboard-document-list')->columnSpanFull()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                Select::make('location_id')->label(__('Branch'))->required()
                    ->options(fn (): array => app(SalesAccess::class)->branches(auth()->user())->pluck('name', 'id')->all())
                    ->default(fn () => getPermissionsTeamId())->disabledOn('edit')->dehydrated()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('items', [])),
                DateTimePicker::make('ordered_at')->label(__('Order date'))->default(now())->seconds(false)->format('Y-m-d H:i:s'),
                TextInput::make('customer_name')->label(__('Customer'))->maxLength(255),
                TextInput::make('customer_phone')->label(__('Customer phone'))->tel()->maxLength(30),
            ]),
            Section::make(__('Sales order items'))->icon('heroicon-o-shopping-bag')->columnSpanFull()->schema([
                Repeater::make('items')->label(__('Order lines'))->defaultItems(1)->minItems(1)->maxItems(200)
                    ->mutateDehydratedStateUsing(fn (?array $state): array => $state ?? [])
                    ->addActionLabel(__('Add order line'))->reorderable(false)->live()->compact()
                    ->extraAttributes(['class' => 'fox-commercial-lines'])
                    ->table(array_map(fn (string $label) => TableColumn::make(__($label))->wrapHeader(),
                        ['Brand / Product', 'Variant', 'Quantity', 'Unit price', 'Discounts', 'Taxes', 'Line total']))->schema([
                            Group::make()->schema([
                                Hidden::make('id'),
                                Hidden::make('description'),
                                Hidden::make('available_quantity')->dehydrated(false),
                                ProductLinePreview::make(self::class, 'variant_id', fn (Get $get, array $ids): Collection => ProductLinePreview::byKeys(app(SalesProductSelection::class)->variants(auth()->user(), $get('../../location_id'), false), $ids)->with(['images', 'product.images'])->get()->keyBy('id'), null, 'components.commercial-product-cell'),
                                Section::make(__('Select product'))->compact()->collapsible()->collapsed(fn (Get $get): bool => filled($get('product_id')))
                                    ->extraAttributes(['class' => 'fox-line-catalog-picker'])->schema([
                                        CommercialSelectors::brand(fn (Get $get) => app(SalesProductSelection::class)->products(auth()->user(), $get('../../location_id'))),
                                        CommercialSelectors::brandPreview(),
                                        Select::make('product_id')->label(__('Product'))->required()->searchable()->preload()->live()->columnSpanFull()
                                            ->options(fn (Get $get): array => app(SalesProductSelection::class)->productOptions(auth()->user(), $get('../../location_id'), '', $get('brand_id')))
                                            ->getSearchResultsUsing(fn (Get $get, string $search): array => app(SalesProductSelection::class)->productOptions(auth()->user(), $get('../../location_id'), $search, $get('brand_id')))
                                            ->getOptionLabelUsing(fn (Get $get, $value): ?string => app(SalesProductSelection::class)->products(auth()->user(), $get('../../location_id'), false)->whereKey($value)->value('name'))
                                            ->afterStateUpdated(function (Set $set): void {
                                                $set('variant_id', null);
                                                $set('description', null);
                                                $set('available_quantity', null);
                                                $set('unit_price', '0');
                                                $set('discount_id', null);
                                                $set('tax_ids', []);
                                            }),
                                    ]),
                            ]),
                            Group::make()->schema([
                                Select::make('variant_id')->wrapOptionLabels(false)->label(__('Variant'))->required()->searchable()->preload()->live()->columnSpanFull()
                                    ->options(fn (Get $get): array => app(SalesProductSelection::class)->variantOptions(auth()->user(), $get('../../location_id'), $get('product_id')))
                                    ->getSearchResultsUsing(fn (Get $get, string $search): array => app(SalesProductSelection::class)->variantOptions(auth()->user(), $get('../../location_id'), $get('product_id'), $search))
                                    ->getOptionLabelUsing(function (Get $get, $value): ?string {
                                        $selection = app(SalesProductSelection::class);
                                        $variant = $selection->variants(auth()->user(), $get('../../location_id'), false)->where('product_id', $get('product_id'))->with(['product', 'size', 'color'])->find($value);

                                        return $variant === null ? null : $selection->label($variant);
                                    })
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        $variant = app(SalesProductSelection::class)->variants(auth()->user(), $get('../../location_id'))->where('product_id', $get('product_id'))->with(['product', 'size', 'color'])->find($get('variant_id'));
                                        $set('unit_price', $variant === null ? '0' : app(SellingPriceResolver::class)->resolve($variant));
                                        $set('description', $variant === null ? null : Str::limit($variant->displayName(), 255, ''));
                                        $set('available_quantity', $variant?->sales_available_quantity ?? '0.0000');
                                        $discounts = $variant === null ? collect() : app(SalesLinePricing::class)->discounts($variant->product);
                                        $set('discount_id', null);
                                        $set('tax_ids', array_keys(self::taxOptions($get)));
                                    }),
                                Hidden::make('notes'),
                                Actions::make([Action::make('lineNotes')->label(__('Line notes'))->icon('heroicon-o-pencil-square')->link()->size('sm')
                                    ->schema([Textarea::make('notes')->label(__('Line notes'))->rows(3)->maxLength(16000)])
                                    ->fillForm(fn (Get $get): array => ['notes' => $get('notes')])
                                    ->action(fn (array $data, Set $set) => $set('notes', $data['notes'] ?? null))]),
                            ]),
                            TextInput::make('quantity')->label(__('Quantity'))->required()->default('1')->type('number')->step('0.0001')->inputMode('decimal')->live(onBlur: true)
                                ->extraInputAttributes(fn (Get $get): array => ['min' => '0.0001', 'max' => $get('available_quantity') ?? '0.0000'])
                                ->hint(fn (Get $get): string => __('Available').': '.QuantityFormatter::format($get('available_quantity') ?? '0.0000')),
                            TextInput::make('unit_price')->label(__('Unit price'))->required()->default('0')->inputMode('decimal')->live(onBlur: true),
                            Group::make()->schema([
                                CommercialSelectors::definition('discounts', 'sales'),
                                self::amountCell('discount_amount', 'Applied discount amount'),
                            ]),
                            Group::make()->schema([
                                CommercialSelectors::definition('taxes', 'sales'),
                                self::amountCell('tax_amount', 'Applied tax amount'),
                            ]),
                            Group::make()->schema([self::amountCell('subtotal', 'Subtotal'), self::amountCell('line_total', 'Line total', true)]),
                        ]),
            ]),
            Group::make()->columnSpanFull()->columns(['default' => 1, 'lg' => 12])->schema([
                Section::make(__('Notes'))->icon('heroicon-o-pencil-square')->columnSpan(['default' => 1, 'lg' => 7])->schema([Textarea::make('notes')->label(__('Notes'))->hiddenLabel()->placeholder(__('Add notes or instructions?'))->rows(4)->maxLength(16000)]),
                Section::make(__('Financial totals'))->columnSpan(['default' => 1, 'lg' => 5])->icon('heroicon-o-calculator')->schema([
                    View::make('components.financial-summary')->viewData(fn (Get $get): array => self::totalsPreview($get) + ['commercial' => true]),
                ]),
            ]),
        ])->columns(['default' => 1, 'lg' => 2]);
    }

    private static function totalsPreview(Get $get): array
    {
        try {
            $calculator = app(PurchaseOrderCalculator::class);
            $lines = array_map(fn (array $line): array => self::price($line, $get('location_id')), $get('items') ?? []);
            $totals = $calculator->totals($lines);

            return ['totals' => $totals];
        } catch (ValidationException $exception) {
            return ['totals' => null, 'notice' => (string) collect($exception->errors())->flatten()->first()];
        }
    }

    private static function price(array $line, ?int $locationId): array
    {
        $cacheKey = 'fox.sales-line-price.'.hash('sha256', json_encode([$locationId, $line], JSON_THROW_ON_ERROR));
        if (request()->attributes->has($cacheKey)) {
            return request()->attributes->get($cacheKey);
        }
        $variant = app(SalesProductSelection::class)->variants(auth()->user(), $locationId, false)
            ->where('product_id', $line['product_id'] ?? null)->with('product')->find($line['variant_id'] ?? null);
        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => __('Choose an active saleable variant belonging to the selected product.')]);
        }

        $result = app(SalesLinePricing::class)->line($variant, Arr::only($line, ['quantity', 'unit_price', 'discount_id', 'tax_ids']));
        request()->attributes->set($cacheKey, $result);

        return $result;
    }

    private static function variant(Get $get): ?ProductVariant
    {
        return app(SalesProductSelection::class)->variants(auth()->user(), $get('../../location_id'), false)
            ->where('product_id', $get('product_id'))->with('product')->find($get('variant_id'));
    }

    private static function discounts(Get $get): Collection
    {
        $variant = self::variant($get);

        return $variant === null ? collect() : app(SalesLinePricing::class)->discounts($variant->product);
    }

    private static function unsupportedDiscount(Get $get): bool
    {
        $discounts = self::discounts($get);
        $discount = filled($get('discount_id')) ? $discounts->firstWhere('id', (int) $get('discount_id')) : ($discounts->count() === 1 ? $discounts->first() : null);

        return $discount !== null && ($discount->type !== 'percentage' || $discount->is_stackable);
    }

    private static function taxOptions(Get $get): array
    {
        $variant = self::variant($get);

        return $variant === null ? [] : app(SalesLinePricing::class)->taxes($variant->product)
            ->mapWithKeys(fn ($tax): array => [$tax->id => $tax->name.' · '.$tax->rate.($tax->type === 'percentage' ? '%' : ' EGP')])->all();
    }

    private static function amountCell(string $field, string $label, bool $emphasized = false): View
    {
        return View::make('components.commercial-line-value')->viewData(function (Get $get) use ($field, $label, $emphasized): array {
            try {
                $line = self::price(Arr::only($get(), ['product_id', 'variant_id', 'quantity', 'unit_price', 'discount_id', 'tax_ids']), $get('../../location_id'));

                return ['field' => $field, 'label' => __($label), 'value' => $line[$field], 'emphasized' => $emphasized];
            } catch (ValidationException $exception) {
                return ['field' => $field, 'label' => __($label), 'value' => '—', 'notice' => $emphasized ? __('Review selected definitions.') : null];
            }
        });
    }
}
