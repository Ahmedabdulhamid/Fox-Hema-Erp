<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Schemas;

use App\Filament\ReadModelCache;
use App\Filament\RequestDisplayData;
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
use App\Modules\Purchasing\Services\PurchasingDisplay;
use App\Modules\Taxes\Services\CommercialDefinitions;
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
                            Select::make('supplier_id')->label(__('Supplier'))->required()->searchable()->live()
                                ->preload()->options(fn (): array => self::supplierOptions())
                                ->searchPrompt(__('Search suppliers by name'))
                                ->getSearchResultsUsing(fn (string $search): array => self::supplierOptions($search))
                                ->getOptionLabelUsing(fn ($value): ?string => self::selectedSupplierLabel($value))
                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                    foreach (array_keys($get('items') ?? []) as $index) {
                                        $set('items.'.$index.'.discount_id', null);
                                    }
                                }),
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
                                    ProductLinePreview::make(self::class, 'variant_id', fn (Get $get, array $ids): Collection => self::displayVariants($get('../../items') ?? [], false)->only($ids)->loadMissing(['images', 'product.images', 'product.brand']), null, 'components.commercial-product-cell'),
                                    Section::make(__('Select product'))->compact()->collapsible()->collapsed(fn (Get $get): bool => filled($get('product_id')))
                                        ->extraAttributes(['class' => 'fox-line-catalog-picker'])->schema([
                                            CommercialSelectors::brand(fn (Get $get) => app(PurchaseOrderProductSelection::class)->products(auth()->user()), cacheOptions: true),
                                            CommercialSelectors::brandPreview(),
                                            Select::make('product_id')->label(__('Product'))->required()->searchable()->preload()->live()->columnSpanFull()
                                                ->options(fn (Get $get): array => self::productOptions('', $get('brand_id')))
                                                ->searchPrompt(__('Search products by name, SKU or barcode'))
                                                ->getSearchResultsUsing(fn (Get $get, string $search): array => self::productOptions($search, $get('brand_id')))
                                                ->getOptionLabelUsing(fn (Get $get, $value): ?string => self::selectedProduct($get, false)?->name)
                                                ->afterStateUpdated(function (Get $get, Set $set): void {
                                                    $product = self::selectedProduct($get);
                                                    $hasVariants = $product !== null && (bool) $product->form_has_variants;
                                                    $managesSizes = $product !== null && ((bool) $product->form_manages_sizes || ! $hasVariants);
                                                    $defaultMode = $hasVariants ? 'known' : 'unknown';
                                                    $mode = $managesSizes
                                                        ? (in_array($get('receiving_mode'), ['unknown', 'known'], true) ? $get('receiving_mode') : $defaultMode) : 'standard';
                                                    $set('receiving_mode', $mode);
                                                    self::resetVariant($get, $set);
                                                }),
                                        ]),
                                ]),
                                Group::make()->columnSpanFull()->columns(['default' => 1, 'md' => 2])->extraAttributes(['class' => 'fox-order-variant'])->schema([
                                    Select::make('receiving_mode')->label(__('Receiving mode'))->live()->required()->dehydratedWhenHidden()
                                        ->options(fn (Get $get): array => self::managesSizesForForm(self::selectedProduct($get))
                                            ? ['unknown' => __('Unknown sizes'), 'known' => __('Known sizes')]
                                            : ['standard' => __('Standard')])
                                        ->visible(fn (Get $get): bool => self::managesSizesForForm(self::selectedProduct($get)))
                                        ->afterStateUpdated(fn (Get $get, Set $set) => self::resetVariant($get, $set)),
                                    Text::make(__('Unknown sizes will be received as unallocated pieces. Choose colors and sizes during allocation; stock moves only when posted.'))->visible(fn (Get $get): bool => $get('receiving_mode') === 'unknown'),
                                    Select::make('variant_id')->wrapOptionLabels()->label(__('Variant'))->required(fn (Get $get): bool => $get('receiving_mode') !== 'unknown')->searchable()->live()->columnSpanFull()
                                        ->visible(fn (Get $get): bool => filled($get('product_id')) && $get('receiving_mode') !== 'unknown')
                                        ->dehydratedWhenHidden()
                                        ->preload()->options(fn (Get $get): array => self::variantOptions($get))
                                        ->searchPrompt(__('Search products by name, SKU, color or size'))
                                        ->getSearchResultsUsing(fn (Get $get, string $search): array => self::variantOptions($get, $search))
                                        ->getOptionLabelUsing(fn (Get $get, $value): ?string => self::selectedVariant($get, $value)?->displayName())
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $set('tax_ids', array_keys(self::taxOptions($get)))),
                                ]),
                                TextInput::make('quantity')->prefixIcon('heroicon-o-cube')->label(__('Ordered quantity'))->required()->default('1')->inputMode('decimal')->live(onBlur: true)
                                    ->partiallyRenderComponentsAfterStateUpdated(['discount_amount', 'tax_amount', 'subtotal', 'line_total', '../../order-summary']),
                                TextInput::make('unit_price')->suffix('EGP')->label(__('Unit price'))->required()->default('0')->inputMode('decimal')->live(onBlur: true)
                                    ->partiallyRenderComponentsAfterStateUpdated(['discount_amount', 'tax_amount', 'subtotal', 'line_total', '../../order-summary']),
                                Group::make()->schema([
                                    CommercialSelectors::definition('discounts', 'purchases', cacheOptions: true,
                                        supplierStatePath: '../../supplier_id')->wrapOptionLabels(),
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
                    Section::make(__('Order summary'))->key('order-summary')->icon('heroicon-o-calculator')->schema([
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
        $query = app(PurchaseOrderService::class)->suppliers(auth()->user())
            ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')->orderBy('id')->limit(50);

        return app(ReadModelCache::class)->options('suppliers', $query);
    }

    private static function selectedSupplierLabel(mixed $value): ?string
    {
        if (! is_scalar($value) || blank($value)) {
            return null;
        }

        $query = app(PurchaseOrderService::class)->suppliers(auth()->user(), false);

        return RequestDisplayData::remember('purchase-form-supplier-label:'.$value,
            fn (): ?string => $query->whereKey($value)->value('name'));
    }

    private static function productOptions(string $search = '', mixed $brandId = null): array
    {
        $query = app(PurchaseOrderProductSelection::class)->products(auth()->user())
            ->when(filled($brandId), fn ($query) => $query->where('brand_id', $brandId))
            ->where(fn ($query) => $query->whereHas('variants', fn ($query) => $query->where('is_active', true))
                ->orWhereDoesntHave('variants'))
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.trim($search).'%')->orWhere('default_sku', 'like', '%'.trim($search).'%')
                ->orWhereHas('variants', fn ($variants) => $variants->where('is_active', true)->where(fn ($variants) => $variants
                    ->where('sku', 'like', '%'.trim($search).'%')->orWhere('barcode', 'like', '%'.trim($search).'%')))))
            ->orderBy('name')->orderBy('id')->limit(50);

        return RequestDisplayData::remember('purchase-form-product-options:'.json_encode([$search, $brandId], JSON_THROW_ON_ERROR),
            fn (): array => app(ReadModelCache::class)->options('catalog', $query));
    }

    private static function selectedProduct(Get $get, bool $activeOnly = true): ?Product
    {
        $query = app(PurchaseOrderProductSelection::class)->products(auth()->user(), $activeOnly);

        $ids = array_values(array_unique(array_column($get('../../items') ?? [], 'product_id')));
        $products = RequestDisplayData::remember('purchase-form-products:'.json_encode([$activeOnly, $ids], JSON_THROW_ON_ERROR),
            fn (): Collection => $query->whereKey($ids)->withExists([
                'variants as form_manages_sizes' => fn ($query) => $query->whereNotNull('size_id')->orWhereNotNull('unallocated_product_id'),
                'variants as form_has_variants',
            ])->with(['taxes' => fn ($query) => $query
                ->whereIn('taxes.id', app(CommercialDefinitions::class)->eligible('taxes', 'purchases')->select('id'))->orderBy('taxes.id')])->get()->keyBy('id'));

        return $products->get($get('product_id'));
    }

    private static function managesSizesForForm(?Product $product): bool
    {
        return $product !== null && ((bool) $product->form_manages_sizes || ! (bool) $product->form_has_variants);
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
        if (trim($search) === '') {
            $productIds = array_values(array_unique(array_column($get('../../items') ?? [], 'product_id')));
            $query = app(PurchaseOrderService::class)->variants(auth()->user())->whereIn('product_id', $productIds)
                ->whereNull('unallocated_product_id')->select('product_variants.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY id) AS variant_option_rank');
            $groups = RequestDisplayData::remember('purchase-form-variant-options:'.json_encode($productIds, JSON_THROW_ON_ERROR),
                fn (): array => app(ReadModelCache::class)->remember('catalog', ['purchase-form-variant-options', $query->toSql(), $query->getBindings()],
                    fn (): array => ProductVariant::query()->fromSub($query->toBase(), 'product_variants')
                        ->where('variant_option_rank', '<=', 50)->with(['product', 'color', 'size'])
                        ->orderBy('product_id')->orderBy('id')->get()->groupBy('product_id')
                        ->map(fn (Collection $variants): array => $variants->mapWithKeys(fn (ProductVariant $variant): array => [$variant->id => $variant->displayName()])->all())->all()));

            return $groups[$get('product_id')] ?? [];
        }

        $query = app(PurchaseOrderService::class)->variants(auth()->user())->with(['color', 'size'])
            ->where('product_id', $get('product_id'))->whereNull('unallocated_product_id')
            ->when(trim($search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', '%'.trim($search).'%')
                ->orWhereHas('product', fn ($products) => $products->where('name', 'like', '%'.trim($search).'%'))
                ->orWhereHas('color', fn ($colors) => $colors->where('name', 'like', '%'.trim($search).'%'))
                ->orWhereHas('size', fn ($sizes) => $sizes->where('name', 'like', '%'.trim($search).'%'))))
            ->orderBy('product_id')->orderBy('id')->limit(50);

        return app(PurchasingDisplay::class)->variantOptions($query);
    }

    private static function selectedVariant(Get $get, mixed $value): ?ProductVariant
    {
        $variant = self::displayVariants($get('../../items') ?? [], false)->get($value);

        return $variant !== null && (string) $variant->product_id === (string) $get('product_id') ? $variant : null;
    }

    private static function displayVariants(array $items, bool $activeOnly = true): Collection
    {
        $query = app(PurchaseOrderService::class)->variants(auth()->user(), $activeOnly);
        $ids = array_values(array_unique(array_column($items, 'variant_id')));
        RequestDisplayData::remember('purchases-form-definitions:'.json_encode($items, JSON_THROW_ON_ERROR), function () use ($items): bool {
            app(CommercialDefinitions::class)->primeDisplayInputs($items, 'purchases');

            return true;
        });

        return RequestDisplayData::remember('purchase-form-variants:'.json_encode([$activeOnly, $ids], JSON_THROW_ON_ERROR),
            fn (): Collection => $query->whereKey($ids)->with(['color', 'size'])->get()->keyBy('id'));
    }

    private static function totalsPreview(Get $get): array
    {
        try {
            $calculator = app(PurchaseOrderCalculator::class);
            $lines = array_map(fn (array $line): array => self::price($line, $get('items') ?? [], $get('supplier_id')), $get('items') ?? []);
            $totals = $calculator->totals($lines);

            return ['totals' => $totals];
        } catch (ValidationException $exception) {
            return ['totals' => null, 'notice' => (string) collect($exception->errors())->flatten()->first()];
        }
    }

    private static function price(array $line, array $items = [], int|string|null $supplierId = null): array
    {
        $line = Arr::only($line, ['product_id', 'variant_id', 'quantity', 'unit_price', 'discount_id', 'tax_ids']);
        $cacheKey = 'fox.purchase-line-price.'.hash('sha256', json_encode([$supplierId, $line], JSON_THROW_ON_ERROR));
        $variant = self::displayVariants($items === [] ? [$line] : $items)->get($line['variant_id'] ?? null);
        if ($variant === null || (string) $variant->product_id !== (string) ($line['product_id'] ?? '')) {
            throw ValidationException::withMessages(['variant_id' => __('Choose an active variant of an active product.')]);
        }

        return RequestDisplayData::remember($cacheKey, fn (): array => app(PurchaseLinePricing::class)
            ->line($variant, Arr::only($line, ['quantity', 'unit_price', 'discount_id', 'tax_ids']), supplierId: $supplierId));
    }

    private static function taxOptions(Get $get): array
    {
        $product = self::selectedProduct($get);

        return $product === null ? [] : $product->taxes
            ->mapWithKeys(fn ($tax): array => [$tax->id => $tax->name.' · '.NumericFormatter::definition($tax->rate, $tax->type)])->all();
    }

    private static function amountCell(string $field, string $label, bool $emphasized = false): View
    {
        return View::make('components.commercial-line-value')->key($field)->viewData(function (Get $get) use ($field, $label, $emphasized): array {
            try {
                $line = self::price(Arr::only($get(), ['product_id', 'variant_id', 'quantity', 'unit_price', 'discount_id', 'tax_ids']),
                    $get('../../items') ?? [], $get('../../supplier_id'));

                return ['field' => $field, 'label' => __($label), 'value' => $line[$field], 'kind' => NumericFormatter::kind($field) ?? 'money', 'emphasized' => $emphasized];
            } catch (ValidationException $exception) {
                return ['field' => $field, 'label' => __($label), 'value' => '—', 'notice' => $emphasized ? __('Review selected definitions.') : null];
            }
        });
    }
}
