<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\InventoryFilters;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

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
        return __('Stock Movements');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = app(InventoryAccess::class)->scope(parent::getEloquentQuery(), auth()->user(), InventoryPermissions::MOVEMENTS)
            ->with(['variant.images', 'variant.product.images', 'variant.product.brand', 'variant:id,product_id,color_id,size_id,sku,barcode,unallocated_product_id',
                'variant.product', 'variant.color', 'variant.size', 'location', 'createdBy']);
        if (! app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST)) {
            $query->select(['stock_movements.id', 'stock_movements.uuid', 'stock_movements.variant_id', 'stock_movements.location_id',
                'stock_movements.type', 'stock_movements.quantity', 'stock_movements.reference_type', 'stock_movements.reference_id',
                'stock_movements.created_by', 'stock_movements.system_actor', 'stock_movements.notes', 'stock_movements.created_at'])
                ->selectRaw('NULL AS unit_cost');
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        $types = [];
        foreach (StockMovementType::cases() as $type) {
            $types[$type->value] = __(ucwords(str_replace('_', ' ', $type->value)));
        }

        return InventoryFilters::configure($table)->columns([
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.inventory-product', ['variant' => $record->variant, 'title' => $state]))->label(__('Product'))->searchable()->sortable(),
            TextColumn::make('variant_label')->label(__('Variant'))->state(fn (StockMovement $record): string => $record->variant->imageLabel())
                ->formatStateUsing(fn ($state, StockMovement $record) => view('components.inventory-variant', ['variant' => $record->variant])),
            TextColumn::make('variant.sku')->toggleable(isToggledHiddenByDefault: true)->label(__('SKU'))->searchable(),
            TextColumn::make('location.name')->label(__('Location'))->sortable(),
            TextColumn::make('type')->label(__('Movement type'))->formatStateUsing(fn (StockMovementType $state): string => $types[$state->value])->badge()->color(fn (StockMovementType $state): string => match ($state) {
                StockMovementType::Purchase, StockMovementType::TransferIn => 'success', StockMovementType::Sale, StockMovementType::TransferOut => 'danger', StockMovementType::Return => 'warning', StockMovementType::Adjustment => 'info'
            }),
            TextColumn::make('quantity')->label(__('Signed quantity'))->sortable()->badge()->extraAttributes(['dir' => 'ltr'])
                ->formatStateUsing(fn (string $state): string => str_starts_with($state, '-') ? QuantityFormatter::format($state) : '+'.QuantityFormatter::format($state))
                ->color(fn (string $state): string => str_starts_with($state, '-') ? 'danger' : 'success'),
            TextColumn::make('display_reference')->label(__('documents.erp_number'))
                ->searchable(query: fn (Builder $query, string $search) => DocumentPresentation::searchNumber($query, $search)),
            TextColumn::make('created_at')->label(__('Created at'))->dateTime('Y-m-d H:i')->sortable(),
            TextColumn::make('unit_cost')->toggleable(isToggledHiddenByDefault: true)->label(__('Unit cost'))->placeholder('—')
                ->visible(fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST)),
            TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true)->label(__('Source'))->state(fn (StockMovement $record): string => $record->reference_type === null ? '—' : class_basename($record->reference_type).' #'.$record->reference_id),
            TextColumn::make('actor')->toggleable(isToggledHiddenByDefault: true)->label(__('Created by'))->state(fn (StockMovement $record): string => $record->createdBy?->name ?? $record->system_actor ?? __('Unknown actor')),
            TextColumn::make('notes')->toggleable(isToggledHiddenByDefault: true)->label(__('Notes'))->wrap()->limit(120),
        ])->filters([
            ...InventoryFilters::catalog(InventoryPermissions::MOVEMENTS, 'movements'),
            SelectFilter::make('type')->label(__('Movement type'))->options($types),
            Filter::make('reference')->label(__('Reference'))->schema([TextInput::make('number')->label(__('Reference'))->live(debounce: 400)])->query(fn (Builder $query, array $data): Builder => filled($data['number'] ?? null) ? DocumentPresentation::searchNumber($query, $data['number']) : $query),
            InventoryFilters::dateRange('created_at', 'stock_movements.created_at')
                ->columns(['default' => 1, 'sm' => 2])->columnSpan(['default' => 'full', 'xl' => 'full']),
        ])->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
            ->filtersFormWidth('4xl')->filtersFormMaxHeight('min(70dvh, calc(100dvh - 8rem))')
            ->defaultSort('created_at', 'desc')->paginationPageOptions([25, 50, 100])
            ->recordUrl(null)->recordActions([
                Action::make('movementDetails')->label(__('View Details'))->modal()->slideOver()->modalWidth('4xl')->modalHeading(__('Stock Movements'))->modalSubmitAction(false)
                    ->modalContent(fn (StockMovement $record) => view('inventory.movement-details', ['record' => $record])),
            ])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
