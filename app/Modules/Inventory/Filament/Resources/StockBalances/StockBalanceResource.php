<?php

namespace App\Modules\Inventory\Filament\Resources\StockBalances;

use App\Models\QuantityFormatter;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\ProductInventorySummary;
use App\Modules\Products\Models\Product;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockBalanceResource extends Resource
{
    protected static ?string $model = StockBalance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationItems(): array
    {
        return static::canAccess() ? parent::getNavigationItems() : [];
    }

    public static function getNavigationGroup(): string
    {
        return __('Inventory');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock Balances');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(InventoryAccess::class)->scope(parent::getEloquentQuery(), auth()->user(), InventoryPermissions::BALANCES)
            ->with(['variant.images', 'variant.product.images', 'variant:id,product_id,color_id,size_id,sku,barcode,unallocated_product_id',
                'variant.product', 'variant.color', 'variant.size', 'location']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('Product'))->searchable()->sortable(),
            TextColumn::make('variant_label')->label(__('Variant'))->state(fn (StockBalance $record): string => $record->variant->imageLabel()),
            TextColumn::make('variant.color.name')->label(__('Color'))->placeholder(__('No color')),
            TextColumn::make('variant.size.name')->label(__('Size'))->placeholder(__('No size')),
            TextColumn::make('variant.sku')->label(__('SKU'))->searchable()->sortable(),
            TextColumn::make('variant.barcode')->label(__('Barcode'))->searchable(),
            TextColumn::make('location.name')->label(__('Location'))->sortable(),
            TextColumn::make('location.type')->label(__('Location type'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('quantity_on_hand')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity on hand'))->alignEnd()->sortable(),
            TextColumn::make('quantity_reserved')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity reserved'))->alignEnd()->sortable(),
            TextColumn::make('quantity_available')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity available'))->alignEnd()->sortable(),
            TextColumn::make('updated_at')->label(__('Last updated'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('location_id')->label(__('Location'))->options(fn (): array => app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('product')->label(__('Product'))->searchable()
                ->getSearchResultsUsing(function (string $search): array {
                    return Product::where('name', 'like', '%'.$search.'%')
                        ->whereHas('variants.stockBalances', fn (Builder $query) => app(InventoryAccess::class)->scope($query, auth()->user(), InventoryPermissions::BALANCES))
                        ->orderBy('name')->limit(50)->pluck('name', 'id')->all();
                })
                ->getOptionLabelUsing(fn ($value): ?string => Product::whereKey($value)
                    ->whereHas('variants.stockBalances', fn (Builder $query) => app(InventoryAccess::class)->scope($query, auth()->user(), InventoryPermissions::BALANCES))->value('name'))
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, $value) => $query->whereHas('variant', fn (Builder $query) => $query->where('product_id', $value)))),
        ])->defaultSort('updated_at', 'desc')->paginationPageOptions([25, 50, 100])
            ->recordUrl(null)->recordActions([
                Action::make('productInventory')->label(__('Product Inventory'))->modalSubmitAction(false)->slideOver()->modalWidth('5xl')
                    ->modalContent(fn (StockBalance $record) => view('components.product-inventory-summary', app(ProductInventorySummary::class)->forProduct(auth()->user(), $record->variant->product, (int) $record->location_id))),
                Action::make('allocateSizes')->label(__('Allocate Sizes'))
                    ->visible(fn (StockBalance $record): bool => $record->variant->isUnallocated()
                        && BigDecimal::of($record->quantity_available)->isPositive()
                        && app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::ALLOCATIONS_VIEW, (int) $record->location_id)
                        && app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::ALLOCATIONS_MANAGE, (int) $record->location_id))
                    ->url(fn (StockBalance $record): string => SizeAllocationResource::getUrl('create', ['product' => $record->variant->product_id, 'location' => $record->location_id])),
            ])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockBalances::route('/')];
    }
}
