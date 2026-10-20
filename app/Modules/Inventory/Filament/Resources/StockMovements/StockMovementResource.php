<?php

namespace App\Modules\Inventory\Filament\Resources\StockMovements;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
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
            ->with(['variant.images', 'variant.product.images', 'variant:id,product_id,color_id,size_id,sku,barcode',
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

        return $table->columns([
            TextColumn::make('display_reference')->label(__('documents.erp_number'))
                ->searchable(query: fn (Builder $query, string $search) => DocumentPresentation::searchNumber($query, $search)),
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('Product'))->searchable()->sortable(),
            TextColumn::make('variant_label')->label(__('Variant'))->state(fn (StockMovement $record): string => $record->variant->imageLabel()),
            TextColumn::make('variant.sku')->label(__('SKU'))->searchable(),
            TextColumn::make('location.name')->label(__('Location'))->sortable(),
            TextColumn::make('type')->label(__('Movement type'))->formatStateUsing(fn (StockMovementType $state): string => $types[$state->value])->badge(),
            TextColumn::make('quantity')->label(__('Signed quantity'))->sortable()->badge()
                ->formatStateUsing(fn (string $state): string => str_starts_with($state, '-') ? QuantityFormatter::format($state) : '+'.QuantityFormatter::format($state))
                ->color(fn (string $state): string => str_starts_with($state, '-') ? 'danger' : 'success'),
            TextColumn::make('unit_cost')->label(__('Unit cost'))->placeholder('—')
                ->visible(fn (): bool => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST)),
            TextColumn::make('source')->label(__('Source'))->state(fn (StockMovement $record): string => $record->reference_type === null ? '—' : class_basename($record->reference_type).' #'.$record->reference_id),
            TextColumn::make('actor')->label(__('Created by'))->state(fn (StockMovement $record): string => $record->createdBy?->name ?? $record->system_actor ?? __('Unknown actor')),
            TextColumn::make('notes')->label(__('Notes'))->wrap()->limit(120),
            TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('location_id')->label(__('Location'))->options(fn (): array => app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::MOVEMENTS)->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('type')->label(__('Movement type'))->options($types),
            Filter::make('created_at')->label(__('Date range'))->schema([
                DatePicker::make('from')->label(__('From')),
                DatePicker::make('until')->label(__('Until'))->afterOrEqual('from'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->where('stock_movements.created_at', '>=', $date.' 00:00:00'))
                ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->where('stock_movements.created_at', '<', Carbon::parse($date)->addDay()->startOfDay()))),
        ])->defaultSort('created_at', 'desc')->paginationPageOptions([25, 50, 100])
            ->recordUrl(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockMovements::route('/')];
    }
}
