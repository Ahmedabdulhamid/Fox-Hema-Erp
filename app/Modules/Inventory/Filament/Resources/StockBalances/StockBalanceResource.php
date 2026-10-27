<?php

namespace App\Modules\Inventory\Filament\Resources\StockBalances;

use App\Models\QuantityFormatter;
use App\Modules\Inventory\Filament\InventoryFilters;
use App\Modules\Inventory\Filament\InventoryListRecords;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Filament\Resources\StockBalances\Pages\ListStockBalances;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
            ->with(['variant.images', 'variant.product.images', 'variant.product.brand', 'variant:id,product_id,color_id,size_id,sku,barcode,unallocated_product_id',
                'variant.product', 'variant.color', 'variant.size', 'location']);
    }

    public static function table(Table $table): Table
    {
        return InventoryFilters::configure($table)->columns([
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.inventory-product', ['variant' => $record->variant, 'title' => $state]))->label(__('Product'))->searchable()->sortable()
                ->url(fn (StockBalance $record): string => ProductInventory::getUrl(['productUuid' => $record->variant->product->uuid, 'location' => $record->location_id, 'variant' => $record->variant_id])),
            TextColumn::make('variant.product.brand.name')->label(__('Brand'))->placeholder(__('No brand'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('variant_label')->label(__('Variant'))->state(fn (StockBalance $record): string => $record->variant->imageLabel())
                ->formatStateUsing(fn ($state, StockBalance $record) => view('components.inventory-variant', ['variant' => $record->variant])),
            TextColumn::make('variant.color.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Color'))->placeholder(__('No color')),
            TextColumn::make('variant.size.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Size'))->placeholder(__('No size')),
            TextColumn::make('variant.sku')->toggleable(isToggledHiddenByDefault: true)->label(__('SKU'))->searchable()->sortable(),
            TextColumn::make('variant.barcode')->toggleable(isToggledHiddenByDefault: true)->label(__('Barcode'))->searchable()
                ->formatStateUsing(fn ($state, StockBalance $record) => view('components.variant-barcode', ['variant' => $record->variant, 'compact' => true])),
            TextColumn::make('location.name')->label(__('Location'))->sortable(),
            TextColumn::make('location.type')->toggleable(isToggledHiddenByDefault: true)->label(__('Location type'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('quantity_on_hand')->badge()->color('info')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity on hand'))->alignEnd()->sortable(),
            TextColumn::make('quantity_reserved')->badge()->color('warning')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity reserved'))->alignEnd()->sortable(),
            TextColumn::make('quantity_available')->badge()->color(fn (?string $state): string => (float) $state <= 0 ? 'danger' : 'success')->formatStateUsing(fn (?string $state): string => QuantityFormatter::format($state))->label(__('Quantity available'))->alignEnd()->sortable(),
            TextColumn::make('updated_at')->toggleable(isToggledHiddenByDefault: true)->label(__('Last updated'))->dateTime()->sortable(),
        ])->filters([
            ...InventoryFilters::catalog(InventoryPermissions::BALANCES, 'balances'),
        ])->defaultSort('updated_at', 'desc')->paginationPageOptions([25, 50, 100])
            ->recordUrl(null)->recordActions([
                Action::make('productInventory')->label(__('View Details'))->button()->color('primary')->icon('heroicon-o-eye')->size('sm')->modalSubmitAction(false)->slideOver()->modalWidth('5xl')
                    ->mountUsing(function (StockBalance $record, InventoryListRecords $livewire): void {
                        $livewire->inventoryDrawerLocation = (int) $record->location_id;
                        $livewire->inventoryDrawerTab = 'overview';
                        $livewire->inventoryDrawerPage = 1;
                    })
                    ->modalContent(fn (StockBalance $record, InventoryListRecords $livewire) => view('inventory.product-stock-details', app(InventoryProductPresentation::class)->details(auth()->user(), $record->variant->product_id, $livewire->inventoryDrawerLocation ?: (int) $record->location_id, $livewire->inventoryDrawerTab === 'sizes', $livewire->inventoryDrawerPage))),
                Action::make('allocateSizes')->label(__('Allocate Now'))
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
