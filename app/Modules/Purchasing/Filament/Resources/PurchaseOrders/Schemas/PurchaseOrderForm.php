<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\NumericFormatter;
use App\Modules\Products\Filament\CommercialSelectors;
use App\Modules\Products\Filament\ProductLinePreview;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Services\PurchaseLinePricing;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Group::make()->extraAttributes(['class' => 'fox-order-form'])->columnSpanFull()->columns(['default' => 1, 'xl' => 12])->schema([
                Group::make()->columnSpan(['default' => 1, 'xl' => 9])->schema([
                    Group::make()->columns(['default' => 1, 'md' => 2])->schema([
                        Section::make(__('Order information'))->icon('heroicon-o-document-text')->columns(['default' => 1, 'sm' => 2])->schema([
                            Select::make('location_id')->label(__('Location'))->required()
                                ->options(fn (): array => app(PurchasingAccess::class)->locations(auth()->user())->pluck('name', 'id')->all())
                                ->default(fn () => getPermissionsTeamId()),

                            DatePicker::make('order_date')->label(__('Order date'))->default(today())->required(),
                        ]),
                        Section::make(__('Supplier information'))->icon('heroicon-o-user-circle')->columns(['default' => 1, 'sm' => 2])->schema([
                            Select::make('supplier_id')->label(__('Supplier'))->required()->searchable()
                                ->preload()->options(fn (): array => self::supplierOptions())
                                ->searchPrompt(__('Search suppliers by name'))
                                ->getSearchResultsUsing(fn (string $search): array => self::supplierOptions($search))
                                ->getOptionLabelUsing(fn ($value): ?string => app(PurchaseOrderService::class)->suppliers(auth()->user(), false)->whereKey($value)->value('name')),
                        ]),
                    ]),
                    Section::make(__('Purchase Order items'))->icon('heroicon-o-shopping-bag')->columnSpanFull()->description(__('Ordered quantities only. Saving or confirming does not receive stock.'))->schema([
                        Repeater::make('items')->label(__('Order lines'))->defaultItems(1)->maxItems(200)->reorderable(false)->live(onBlur: true)->compact()
                            ->extraAttributes(['class' => 'fox-order-items'])
                            ->hiddenLabel()->collapsible()->itemLabel(__('Order line'))->itemNumbers()
                            ->columns(['default' => 1, 'md' => 2])
                            ->addAction(fn (Action $action): Action => $action->icon('heroicon-o-plus')->color('primary'))
                            ->deleteAction(fn (Action $action): Action => $action->icon('heroicon-o-trash')->color('danger'))
                            ->addActionLabel(__('Add order line'))
                            ->schema([
                                Group::make()->columnSpanFull()->extraAttributes(['class' => 'fox-order-product'])->schema([
                                    Hidden::make('id'),
                                    ProductLinePreview::make(self::class, 'variant_id', fn (Get $get, array $ids): Collection => ProductLinePreview::byKeys(app(PurchaseOrderService::class)->variants(auth()->user(), false), $ids)->with(['images', 'product.images', 'product.brand', 'color', 'size'])->get()->keyBy('id'), null, 'components.commercial-product-cell'),
                                    Section::make(__('Select product'))->compact()->collapsible()->collapsed(fn (Get $get): bool => filled($get('product_id')))
                                        ->extraAttributes(['class' => 'fox-line-catalog-picker'])->schema([
                                            CommercialSelectors::brand(fn (Get $get) => app(PurchaseOrderProductSelection::class)->products(auth()->user())),
                                            CommercialSelectors::brandPreview(),
                                            Select::make('product_id')->label(__('Product'))->required()->searchable()->preload()->live()->columnSpanFull()
                                                ->options(fn (Get $get): array => self::productOptions('', $get('brand_id')))
                                                ->searchPrompt(__('Search products by name, SKU or barcode'))
                                                ->getSearchResultsUsing(fn (Get $get, string $search): array => self::productOptions($search, $get('brand_id')))
                                                ->getOptionLabelUsing(fn ($value): ?string => app(PurchaseOrderProductSelection::class)->products(auth()->user(), false)->whereKey($value)->value('name'))
                                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                                    $product = self::selectedProduct($get);
                                                    $mode = $product !== null && app(PurchaseOrderProductSelection::class)->managesSizes($product)
                                                        ? (in_array($get('receiving_mode'), ['unknown', 'known'], true) ? $get('receiving_mode') : 'unknown') : 'standard';
                                                    $set('receiving_mode', $mode);
                                                    self::resetVariant($get, $set);
                                                }),
                                        ]),
                                ]),
                                Group::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->extraAttributes(['class' => 'fox-order-variant'])->schema([
                                    Select::make('receiving_mode')->label(__('Receiving mode'))->live()->required()->dehydratedWhenHidden()
                                        ->options(fn (Get $get): array => ($product = self::selectedProduct($get)) !== null && app(PurchaseOrderProductSelection::class)->managesSizes($product)
                                            ? ['unknown' => __('Unknown sizes'), 'known' => __('Known sizes')]
                                            : ['standard' => __('Standard')])
                                        ->visible(fn (Get $get): bool => ($product = self::selectedProduct($get)) !== null && app(PurchaseOrderProductSelection::class)->managesSizes($product))
                                        ->afterStateUpdated(fn (Get $get, Set $set) => self::resetVariant($get, $set)),
                                    Text::make(__('Sizes Not Yet Allocated'))->visible(fn (Get $get): bool => $get('receiving_mode') === 'unknown'),
                                    Select::make('variant_id')->wrapOptionLabels()->label(__('Variant'))->required(fn (Get $get): bool => $get('receiving_mode') !== 'unknown')->searchable()->live()->columnSpanFull()
                                        ->visible(fn (Get $get): bool => filled($get('product_id')) && $get('receiving_mode') !== 'unknown')
                                        ->dehydratedWhenHidden()
                                        ->preload()->options(fn (Get $get): array => self::variantOptions($get))
                                        ->searchPrompt(__('Search products by name, SKU, color or size'))
                                        ->getSearchResultsUsing(fn (Get $get, string $search): array => self::variantOptions($get, $search))
                                        ->getOptionLabelUsing(fn (Get $get, $value): ?string => app(PurchaseOrderService::class)->variants(auth()->user(), false)->where('product_id', $get('product_id'))->with(['color', 'size'])->find($value)?->displayName())
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('tax_ids', array_keys(self::taxOptions($get)))),
                                ]),
                                TextInput::make('quantity')->prefixIcon('heroicon-o-cube')->label(__('Ordered quantity'))->required()->default('1')->inputMode('decimal')->live(onBlur: true),
                                TextInput::make('unit_price')->suffix('EGP')->label(__('Unit price'))->required()->default('0')->inputMode('decimal')->live(onBlur: true),
                                Group::make()->schema([
                                    CommercialSelectors::definition('discounts', 'purchases', cacheOptions: true)->wrapOptionLabels(),
                                    self::amountCell('discount_amount', 'Applied discount amount'),
                                ]),
                                Group::make()->schema([
                                    CommercialSelectors::definition('taxes', 'purchases', cacheOptions: true)->wrapOptionLabels(),
                                    self::amountCell('tax_amount', 'Applied tax amount'),
                                ]),
                                Group::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->extraAttributes(['class' => 'fox-order-line-total'])->schema([self::amountCell('subtotal', 'Subtotal'), self::amountCell('line_total', 'Line total', true)]),
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
                    ]),
                    Section::make(__('Additional information'))->icon('heroicon-o-information-circle')->schema([
                        DatePicker::make('expected_date')->label(__('Expected delivery date'))->afterOrEqual('order_date'),
                        Textarea::make('notes')->label(__('Notes'))->placeholder(__('Add notes or instructions?'))->rows(5)->maxLength(16000),
                    ]),
                ]),
            ]),
        ])->columns(1);
    }

    private static function supplierOptions(string $search = ''): array
    {
        return app(PurchaseOrderService::class)->suppliers(auth()->user())
            ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')->orderBy('id')->limit(50)->pluck('name', 'id')->all();
    }

    private static function productOptions(string $search = '', mixed $brandId = null): array
    {
        return app(PurchaseOrderProductSelection::class)->products(auth()->user())
            ->when(filled($brandId), fn ($query) => $query->where('brand_id', $brandId))
            ->whereHas('variants', fn ($query) => $query->where('is_active', true))
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.trim($search).'%')->orWhere('default_sku', 'like', '%'.trim($search).'%')
                ->orWhereHas('variants', fn ($variants) => $variants->where('is_active', true)->where(fn ($variants) => $variants
                    ->where('sku', 'like', '%'.trim($search).'%')->orWhere('barcode', 'like', '%'.trim($search).'%')))))
            ->orderBy('name')->orderBy('id')->limit(50)->pluck('name', 'id')->all();
    }

    private static function selectedProduct(Get $get): ?Product
    {
        return app(PurchaseOrderProductSelection::class)->products(auth()->user())->find($get('product_id'));
    }

    private static function resetVariant(Get $get, Set $set): void
    {
        $set('variant_id', null);
        $set('discount_id', null);
        $set('tax_ids', []);
        $product = self::selectedProduct($get);
        if ($product === null) {
            return;
        }
        if ($get('receiving_mode') === 'unknown') {
            $set('variant_id', $product->variants()->where('unallocated_product_id', $product->id)->where('is_active', true)->value('id'));
        } elseif ($get('receiving_mode') === 'standard') {
            $variants = $product->variants()->whereNull('unallocated_product_id')->where('is_active', true)->limit(2)->get();
            if ($variants->count() === 1) {
                $set('variant_id', $variants->first()->id);
            }
        }
        $set('tax_ids', array_keys(self::taxOptions($get)));
    }

    private static function variantOptions(Get $get, string $search = ''): array
    {
        return app(PurchaseOrderService::class)->variants(auth()->user())->with(['color', 'size'])
            ->where('product_id', $get('product_id'))->whereNull('unallocated_product_id')
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', '%'.trim($search).'%')
                ->orWhereHas('product', fn ($products) => $products->where('name', 'like', '%'.trim($search).'%'))
                ->orWhereHas('color', fn ($colors) => $colors->where('name', 'like', '%'.trim($search).'%'))
                ->orWhereHas('size', fn ($sizes) => $sizes->where('name', 'like', '%'.trim($search).'%'))))
            ->orderBy('product_id')->orderBy('id')->limit(50)->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->displayName()])->all();
    }

    private static function totalsPreview(Get $get): array
    {
        try {
            $calculator = app(PurchaseOrderCalculator::class);
            $lines = array_map(fn (array $line): array => self::price($line), $get('items') ?? []);
            $totals = $calculator->totals($lines);

            return ['totals' => $totals];
        } catch (ValidationException $exception) {
            return ['totals' => null, 'notice' => (string) collect($exception->errors())->flatten()->first()];
        }
    }

    private static function price(array $line): array
    {
        $cacheKey = 'fox.purchase-line-price.'.hash('sha256', json_encode($line, JSON_THROW_ON_ERROR));
        if (request()->attributes->has($cacheKey)) {
            return request()->attributes->get($cacheKey);
        }
        $variant = app(PurchaseOrderService::class)->variants(auth()->user())
            ->where('product_id', $line['product_id'] ?? null)->find($line['variant_id'] ?? null);
        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => __('Choose an active variant of an active product.')]);
        }

        $result = app(PurchaseLinePricing::class)->line($variant, Arr::only($line, ['quantity', 'unit_price', 'discount_id', 'tax_ids']));
        request()->attributes->set($cacheKey, $result);

        return $result;
    }

    private static function taxOptions(Get $get): array
    {
        $product = self::selectedProduct($get);

        return $product === null ? [] : app(PurchaseLinePricing::class)->taxes($product)
            ->mapWithKeys(fn ($tax): array => [$tax->id => $tax->name.' · '.NumericFormatter::definition($tax->rate, $tax->type)])->all();
    }

    private static function amountCell(string $field, string $label, bool $emphasized = false): View
    {
        return View::make('components.commercial-line-value')->viewData(function (Get $get) use ($field, $label, $emphasized): array {
            try {
                $line = self::price(Arr::only($get(), ['product_id', 'variant_id', 'quantity', 'unit_price', 'discount_id', 'tax_ids']));

                return ['field' => $field, 'label' => __($label), 'value' => $line[$field], 'kind' => NumericFormatter::kind($field) ?? 'money', 'emphasized' => $emphasized];
            } catch (ValidationException $exception) {
                return ['field' => $field, 'label' => __($label), 'value' => '—', 'notice' => $emphasized ? __('Review selected definitions.') : null];
            }
        });
    }
}
