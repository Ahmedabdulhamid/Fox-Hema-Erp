<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Schemas;

use App\Models\NumericFormatter;
use App\Models\QuantityFormatter;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Sales\Services\SalesProductSelection;
use App\Modules\Sales\Services\SalesProfitability;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Group::make()->extraAttributes(['class' => 'fox-order-form'])->columnSpanFull()->columns(['default' => 1, 'xl' => 12])->schema([
                Group::make()->columnSpan(['default' => 1, 'xl' => 9])->schema([
                    Group::make()->columns(['default' => 1, 'md' => 2])->schema([
                        Section::make(__('Order information'))->icon('heroicon-o-document-text')->columns(['default' => 1, 'sm' => 2])->schema([
                            Select::make('location_id')->label(__('Branch'))->required()
                                ->options(fn (): array => app(SalesAccess::class)->branches(auth()->user())->pluck('name', 'id')->all())
                                ->default(fn () => getPermissionsTeamId())->disabledOn('edit')->dehydrated()->live()
                                ->afterStateUpdated(fn (Set $set) => $set('items', [])),
                            DateTimePicker::make('ordered_at')->label(__('Order date'))->default(now())->seconds(false)->format('Y-m-d H:i:s'),
                        ]),
                        Section::make(__('Customer information'))->icon('heroicon-o-user-circle')->columns(['default' => 1, 'sm' => 2])->schema([
                            TextInput::make('customer_name')->label(__('Customer'))->maxLength(255),
                            TextInput::make('customer_phone')->label(__('Customer phone'))->tel()->maxLength(30),
                        ]),
                    ]),
                    Section::make(__('Sales order items'))->icon('heroicon-o-shopping-bag')->columnSpanFull()->schema([
                        Repeater::make('items')->label(__('Order lines'))->defaultItems(1)->minItems(1)->maxItems(200)
                            ->mutateDehydratedStateUsing(fn (?array $state): array => $state ?? [])
                            ->addActionLabel(__('Add order line'))->reorderable(false)->live(onBlur: true)->compact()
                            ->extraAttributes(['class' => 'fox-order-items'])
                            ->hiddenLabel()->collapsible()->itemLabel(__('Order line'))->itemNumbers()
                            ->columns(['default' => 1, 'md' => 2])
                            ->addAction(fn (Action $action): Action => $action->icon('heroicon-o-plus')->color('primary'))
                            ->deleteAction(fn (Action $action): Action => $action->icon('heroicon-o-trash')->color('danger'))
                            ->schema([
                                Group::make()->columnSpanFull()->extraAttributes(['class' => 'fox-order-product'])->schema([
                                    Hidden::make('id'),
                                    Hidden::make('description'),
                                    Hidden::make('available_quantity')->dehydrated(false),
                                    ProductLinePreview::make(self::class, 'variant_id', fn (Get $get, array $ids): Collection => ProductLinePreview::byKeys(app(SalesProductSelection::class)->variants(auth()->user(), $get('../../location_id'), false), $ids)->with(['images', 'product.images', 'product.brand', 'color', 'size'])->get()->keyBy('id'), null, 'components.commercial-product-cell'),
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
                                Group::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->extraAttributes(['class' => 'fox-order-variant'])->schema([
                                    Select::make('variant_id')->wrapOptionLabels()->label(__('Variant'))->required()->searchable()->preload()->live()->columnSpanFull()
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
                                ]),
                                TextInput::make('quantity')->prefixIcon('heroicon-o-cube')->label(__('Quantity'))->required()->default('1')->type('number')->step('0.0001')->inputMode('decimal')->live(onBlur: true)
                                    ->extraInputAttributes(fn (Get $get): array => ['min' => '0.0001', 'max' => $get('available_quantity') ?? '0.0000'])
                                    ->hint(fn (Get $get): string => __('Available').': '.QuantityFormatter::format($get('available_quantity') ?? '0.0000')),
                                TextInput::make('unit_price')->suffix('EGP')->label(__('Unit price'))->required()->default('0')->inputMode('decimal')->live(onBlur: true),
                                Group::make()->schema([
                                    CommercialSelectors::definition('discounts', 'sales', cacheOptions: true)->wrapOptionLabels(),
                                    self::amountCell('discount_amount', 'Applied discount amount'),
                                ]),
                                Group::make()->schema([
                                    CommercialSelectors::definition('taxes', 'sales', cacheOptions: true)->wrapOptionLabels(),
                                    self::amountCell('tax_amount', 'Applied tax amount'),
                                ]),
                                Group::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->extraAttributes(['class' => 'fox-order-line-total'])->schema([self::amountCell('subtotal', 'Subtotal'), self::amountCell('line_total', 'Line total', true),
                                    View::make('components.profitability-line')->visible(fn (Get $get): bool => app(SalesProfitability::class)->canView(auth()->user(), $get('../../location_id')))
                                        ->viewData(function (Get $get, View $component): array {
                                            $key = last(explode('.', $component->getContainer()->getStatePath()));

                                            return ['profitability' => self::profitabilityPreview($get('../../items') ?? [], $get('../../location_id'))['lines'][$key] ?? null];
                                        }),
                                ]),
                                Section::make(__('Line notes'))->icon('heroicon-o-pencil-square')->columnSpanFull()->compact()->collapsible()->collapsed()
                                    ->extraAttributes(['class' => 'fox-order-line-notes'])->schema([
                                        Textarea::make('notes')->label(__('Line notes'))->hiddenLabel()->rows(2)->maxLength(16000),
                                    ]),
                            ]),
                    ]),
                ]),
                Group::make()->columnSpan(['default' => 1, 'xl' => 3])->extraAttributes(['class' => 'fox-order-aside'])->schema([
                    Section::make(__('Order summary'))->icon('heroicon-o-calculator')->schema([
                        View::make('components.financial-summary')->viewData(fn (Get $get): array => self::totalsPreview($get) + ['commercial' => true]),
                        View::make('components.profitability-summary')->visible(fn (Get $get): bool => app(SalesProfitability::class)->canView(auth()->user(), $get('location_id')))
                            ->viewData(fn (Get $get): array => ['profitability' => self::profitabilityPreview($get('items') ?? [], $get('location_id'))['summary'] ?? []]),
                    ]),
                    Section::make(__('Additional information'))->icon('heroicon-o-information-circle')->schema([
                        Textarea::make('notes')->label(__('Notes'))->placeholder(__('Add notes or instructions?'))->rows(5)->maxLength(16000),
                    ]),
                ]),
            ]),
        ])->columns(1);
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

    private static function profitabilityPreview(array $items, ?int $locationId): array
    {
        abort_unless(app(SalesProfitability::class)->canView(auth()->user(), $locationId), 403);
        if ($locationId === null) {
            return [];
        }
        $key = 'sales-profitability-preview.'.hash('sha256', json_encode([$locationId, $items], JSON_THROW_ON_ERROR));
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        try {
            $lines = [];
            foreach ($items as $id => $item) {
                $lines[$id] = self::price($item, $locationId) + ['variant_id' => (int) ($item['variant_id'] ?? 0)];
            }
            $service = app(SalesProfitability::class);
            $estimates = $service->estimates($lines, $locationId);
            $result = ['lines' => $estimates, 'summary' => $service->summarize($estimates)];
        } catch (ValidationException) {
            $result = [];
        }
        request()->attributes->set($key, $result);

        return $result;
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
            ->mapWithKeys(fn ($tax): array => [$tax->id => $tax->name.' · '.NumericFormatter::definition($tax->rate, $tax->type)])->all();
    }

    private static function amountCell(string $field, string $label, bool $emphasized = false): View
    {
        return View::make('components.commercial-line-value')->viewData(function (Get $get) use ($field, $label, $emphasized): array {
            try {
                $line = self::price(Arr::only($get(), ['product_id', 'variant_id', 'quantity', 'unit_price', 'discount_id', 'tax_ids']), $get('../../location_id'));

                return ['field' => $field, 'label' => __($label), 'value' => $line[$field], 'kind' => NumericFormatter::kind($field) ?? 'money', 'emphasized' => $emphasized];
            } catch (ValidationException $exception) {
                return ['field' => $field, 'label' => __($label), 'value' => '—', 'notice' => $emphasized ? __('Review selected definitions.') : null];
            }
        });
    }
}
