<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations;

use App\Models\QuantityFormatter;
use App\Modules\Inventory\Filament\InventoryFilters;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\CreateSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\EditSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ListSizeAllocations;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\ViewSizeAllocation;
use App\Modules\Inventory\Models\SizeAllocation;
use App\Modules\Inventory\Models\SizeAllocationItem;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\SizeAllocationPresentation;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SizeAllocationResource extends Resource
{
    protected static ?string $model = SizeAllocation::class;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Inventory');
    }

    public static function getModelLabel(): string
    {
        return __('Size Allocation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Size Allocations');
    }

    public static function getNavigationItems(): array
    {
        return static::canAccess() ? parent::getNavigationItems() : [];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(InventoryAccess::class)->scope(parent::getEloquentQuery(), auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW)
            ->with(['product.images', 'product.brand', 'sourceVariant.images', 'sourceVariant.product.images', 'sourceVariant.product.brand', 'location', 'items.variant.size', 'items.variant.color',
                'items.movements' => fn ($q) => $q->select(['id', 'uuid', 'reference_type', 'reference_id'])]);
    }

    public static function form(Schema $schema): Schema
    {
        $presentation = app(SizeAllocationPresentation::class);
        $context = fn (Get $get, ?SizeAllocation $record): array => ['context' => $presentation->context(auth()->user(), $get('') ?? [], $record)];

        return $schema->components([
            View::make('components.size-allocation-workflow')->viewData($context),
            Section::make(__('Allocation context'))->description(__('Choose the brand, product and source stock before distributing quantities.'))
                ->extraAttributes(['class' => 'allocation-selectors'])->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    Select::make('brand_id')->label('1. '.__('Brand'))->required()->searchable()->preload()->live()->dehydrated(false)
                        ->default(fn (): ?string => isset($presentation->brands(auth()->user())['none']) ? 'none' : null)
                        ->placeholder(__('Select Brand'))
                        ->options(fn (?SizeAllocation $record): array => $presentation->brands(auth()->user(), record: $record))
                        ->getSearchResultsUsing(fn (string $search, ?SizeAllocation $record): array => $presentation->brands(auth()->user(), $search, $record))
                        ->getOptionLabelUsing(fn ($value, ?SizeAllocation $record): ?string => $presentation->brandLabel(auth()->user(), $value, $record))
                        ->noOptionsMessage(__('No eligible brands. Receive unallocated stock at an authorized location first.'))
                        ->noSearchResultsMessage(__('No eligible brands match your search.'))
                        ->helperText(fn (?SizeAllocation $record): ?string => $presentation->brands(auth()->user(), record: $record) === [] ? __('No eligible brands. Receive unallocated stock at an authorized location first.') : null)
                        ->disabledOn('edit')->afterStateUpdated(function (Set $set): void {
                            $set('product_id', null);
                            $set('location_id', null);
                            $set('allocation_color', null);
                            $set('items', []);
                        }),
                    Select::make('product_id')->label('2. '.__('Product'))->required()->searchable()->preload()->live()
                        ->placeholder(__('Select Product'))->disabled(fn (Get $get, string $operation): bool => $operation === 'edit' || blank($get('brand_id')))->dehydrated()
                        ->options(fn (Get $get, ?SizeAllocation $record): array => $presentation->productOptions(auth()->user(), $get('brand_id'), record: $record))
                        ->getSearchResultsUsing(fn (Get $get, string $search, ?SizeAllocation $record): array => $presentation->productOptions(auth()->user(), $get('brand_id'), $search, $record))
                        ->getOptionLabelUsing(fn (Get $get, $value, ?SizeAllocation $record): ?string => $presentation->productLabel(auth()->user(), $get('brand_id'), $value, $record))
                        ->noOptionsMessage(__('No products with available unallocated stock for this brand.'))
                        ->afterStateUpdated(function (Set $set, $state): void {
                            $set('location_id', null);
                            $set('allocation_color', null);
                            $set('items', self::grid($state));
                        }),
                    Select::make('allocation_color')->label('3. '.__('Color'))->searchable()->preload()->live()->dehydrated(false)
                        ->placeholder(__('All colors'))->options(fn (Get $get, ?SizeAllocation $record): array => $presentation->colors(auth()->user(), $get('product_id'), $record))
                        ->disabled(fn (Get $get, string $operation): bool => $operation === 'edit' || blank($get('product_id')))
                        ->afterStateUpdated(fn (Set $set, Get $get, $state) => $set('items', self::grid($get('product_id'), $state))),
                    Select::make('location_id')->label('4. '.__('Location'))->required()->searchable()->preload()->live()
                        ->placeholder(__('Select source location'))->default(fn () => getPermissionsTeamId())
                        ->options(fn (Get $get, ?SizeAllocation $record): array => $presentation->locations(auth()->user(), $get('product_id'), $record))
                        ->getSearchResultsUsing(fn (Get $get, string $search, ?SizeAllocation $record): array => $presentation->locations(auth()->user(), $get('product_id'), $record, $search))
                        ->getOptionLabelUsing(fn (Get $get, $value, ?SizeAllocation $record): ?string => $presentation->locations(auth()->user(), $get('product_id'), $record, selectedId: $value)[$value] ?? null)
                        ->disabled(fn (Get $get, string $operation): bool => $operation === 'edit' || blank($get('product_id')))->dehydrated()
                        ->noOptionsMessage(__('No available unallocated stock at your authorized locations.'))
                        ->afterStateUpdated(fn (Set $set, Get $get) => $set('items', self::grid($get('product_id'), $get('allocation_color')))),
                ]),
            View::make('components.size-allocation-stock')->viewData($context),
            Grid::make(['default' => 1, 'lg' => 12])->extraAttributes(['class' => 'allocation-workspace'])->schema([
                Section::make(__('Allocate quantities by size'))->description(__('Enter whole pieces. Existing size and color identities are preserved.'))
                    ->extraAttributes(['class' => 'allocation-grid-card'])->columnSpan(['default' => 1, 'lg' => 8])->schema([
                        Repeater::make('items')->hiddenLabel()->defaultItems(0)->minItems(1)->maxItems(99)->reorderable(false)->live()->addable(false)->deletable(false)
                            ->rules([fn (Get $get, ?SizeAllocation $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record, $presentation): void {
                                $state = $get('') ?? [];
                                $state['items'] = $value ?? [];
                                $context = $presentation->context(auth()->user(), $state, $record);
                                if ($context['over_allocated']) {
                                    $fail(__('Allocation exceeds available unallocated stock at this location.'));
                                } elseif (! $context['is_valid']) {
                                    $fail(__('Choose eligible source stock and enter positive whole-piece quantities.'));
                                }
                            }])
                            ->table([TableColumn::make(__('Size')), TableColumn::make(__('Allocate pieces')), TableColumn::make(__('Current available stock')), TableColumn::make(__('Quantity after allocation'))])
                            ->schema([
                                Hidden::make('variant_id')->required()->hidden()->dehydratedWhenHidden(),
                                TextEntry::make('size_display')->hiddenLabel()->state('variant')->formatStateUsing(fn (Get $get, ?SizeAllocation $record) => view('components.allocation-variant', ['variant' => self::presentationVariants($get, $record)->get($get('variant_id')), 'colorName' => $get('color_label'), 'sizeName' => $get('size_label')])),
                                TextInput::make('quantity')->hiddenLabel()->required()->numeric()->minValue(0)->step(1)->inputMode('numeric')->live(debounce: 300)
                                    ->rules(['regex:/^\d{1,14}(?:\.0{1,4})?$/D'])
                                    ->prefixAction(Action::make('decrease')->label(__('Decrease quantity'))->icon('heroicon-m-minus')
                                        ->action(fn (Get $get, Set $set) => $set('quantity', self::changeQuantity($get('quantity'), -1))))
                                    ->suffixAction(Action::make('increase')->label(__('Increase quantity'))->icon('heroicon-m-plus')
                                        ->action(fn (Get $get, Set $set) => $set('quantity', self::changeQuantity($get('quantity'), 1)))),
                                TextEntry::make('target_available')->hiddenLabel()->state(fn (Get $get): string => QuantityFormatter::format(self::balance($get('variant_id'), $get('../../location_id'))?->quantity_available ?? '0')),
                                TextEntry::make('target_after')->hiddenLabel()->state(function (Get $get): string {
                                    $quantity = (string) ($get('quantity') ?? '');

                                    return preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', $quantity)
                                        ? QuantityFormatter::format((string) BigDecimal::of(self::balance($get('variant_id'), $get('../../location_id'))?->quantity_on_hand ?? '0')->plus($quantity)) : '—';
                                }),
                            ]),
                        Textarea::make('notes')->label(__('Notes / reference'))->rows(2)->maxLength(10000),
                        Text::make(__('Missing sizes can be created in Products → Variants using the existing size catalog.')),
                    ]),
                View::make('components.size-allocation-summary')->viewData($context)->columnSpan(['default' => 1, 'lg' => 4]),
            ]),
        ])->columns(1)->extraAttributes(['class' => 'allocation-form']);
    }

    public static function changeQuantity(mixed $quantity, int $change): string
    {
        if (! preg_match('/^\d{1,14}(?:\.0{1,4})?$/D', (string) $quantity)) {
            return '0';
        }

        return (string) BigDecimal::max(BigDecimal::of((string) $quantity)->plus($change), BigDecimal::zero())->toScale(0);
    }

    public static function eligibleProducts(): Builder
    {
        return app(SizeAllocationPresentation::class)->products(auth()->user());
    }

    private static function balance(mixed $variantId, mixed $locationId): ?StockBalance
    {
        return app(InventoryAccess::class)->scope(StockBalance::query(), auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW)
            ->where('variant_id', $variantId)->where('location_id', $locationId)->first();
    }

    private static function presentationVariants(Get $get, ?SizeAllocation $record): Collection
    {
        $productId = $get('../../product_id');
        $variantIds = collect($get('../../items') ?? [])->pluck('variant_id')->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id))->unique()->sort()->values()->all();
        $key = 'fox.allocation-color.'.hash('sha256', json_encode([$productId, $record?->getKey(), $variantIds], JSON_THROW_ON_ERROR));
        if (! request()->attributes->has($key)) {
            $variants = app(SizeAllocationPresentation::class)->targets(auth()->user(), $productId, record: $record)
                ->whereKey($variantIds)->with(['color', 'size'])->get()->keyBy('id');
            request()->attributes->set($key, $variants);
        }

        return request()->attributes->get($key);
    }

    public static function grid(mixed $productId, mixed $colorId = null): array
    {
        return app(SizeAllocationPresentation::class)->grid(auth()->user(), $productId, $colorId);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('components.document-overview')->columnSpanFull(),
            TextEntry::make('reference')->label(__('Reference')),
            TextEntry::make('status')->label(__('Status'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextEntry::make('product.name')->label(__('Product')),
            TextEntry::make('location.name')->label(__('Location')),
            TextEntry::make('createdBy.name')->label(__('Created by')),
            TextEntry::make('postedBy.name')->label(__('Posted by')),
            TextEntry::make('posted_at')->label(__('Posted at'))->dateTime(),
            TextEntry::make('notes')->label(__('Notes')),
            RepeatableEntry::make('items')->label(__('Allocated sizes'))->extraAttributes(['class' => 'erp-document-evidence'])
                ->table(array_map(fn ($label) => RepeatableEntry\TableColumn::make(__($label))->wrapHeader(),
                    ['SKU', 'Size', 'Color', 'Quantity', 'Linked stock movements']))->schema([
                        TextEntry::make('variant.sku')->label(__('SKU')),
                        TextEntry::make('variant.size.name')->label(__('Size')),
                        TextEntry::make('variant.color.name')->label(__('Color')),
                        TextEntry::make('quantity')->label(__('Quantity')),
                        TextEntry::make('movement_references')->label(__('Linked stock movements'))
                            ->state(fn ($record): string => $record->movements->pluck('display_reference')->implode(', ')),
                    ])->columnSpanFull(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return InventoryFilters::configure($table)->columns([
            TextColumn::make('reference')->label(__('Reference'))->searchable(),
            TextColumn::make('product.name')->label(__('Product'))->searchable()->formatStateUsing(fn ($state, SizeAllocation $record) => view('components.inventory-product', ['variant' => $record->sourceVariant, 'title' => $state])),
            TextColumn::make('location.name')->label(__('Location')),
            TextColumn::make('allocated_quantity')->label(__('Allocated pieces'))->badge()->color('info')->alignEnd()
                ->state(fn (SizeAllocation $record): string => QuantityFormatter::format((string) $record->items->reduce(fn (BigDecimal $total, SizeAllocationItem $item): BigDecimal => $total->plus($item->quantity), BigDecimal::zero()))),
            TextColumn::make('status')->label(__('Document status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('created_at')->label(__('Created at'))->dateTime(),
            TextColumn::make('posted_at')->label(__('Posted at'))->dateTime()->placeholder('—'),
        ])->filters([
            ...InventoryFilters::catalog(InventoryPermissions::ALLOCATIONS_VIEW, 'allocations', 'product_id', color: true),
            SelectFilter::make('status')->label(__('Document status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled')]),
        ])->recordActions([ViewAction::make(), EditAction::make()])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSizeAllocations::route('/'), 'create' => CreateSizeAllocation::route('/create'),
            'view' => ViewSizeAllocation::route('/{record}'), 'edit' => EditSizeAllocation::route('/{record}/edit'),
        ];
    }
}
