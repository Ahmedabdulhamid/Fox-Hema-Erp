<?php

namespace App\Modules\Inventory\Filament\Resources\QuarantineReceipts;

use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuarantineReceiptResource extends Resource
{
    protected static ?string $model = QuarantineReceipt::class;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Inventory');
    }

    public static function getModelLabel(): string
    {
        return __('returns.quarantine');
    }

    public static function getPluralModelLabel(): string
    {
        return __('returns.quarantine');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(InventoryAccess::class)->scope(parent::getEloquentQuery(), auth()->user(), InventoryPermissions::QUARANTINE_VIEW)
            ->with(['variant.images', 'variant.product.images', 'variant.product', 'location', 'allocation.salesReturn', 'receivedBy', 'inspections'])->withCount('inspections');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_reference')->label(__('returns.custody_reference'))->copyable()
                ->searchable(query: fn (Builder $query, string $search) => DocumentPresentation::searchNumber($query, $search)),
            TextColumn::make('location.name')->label(__('returns.branch')),
            TextColumn::make('variant.sku')->label(__('returns.sku'))->searchable(),
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->variant, 'title' => $state]))->label(__('returns.product'))->searchable(),
            TextColumn::make('allocation.salesReturn.return_number')->label(__('returns.return_number')),
            TextColumn::make('quantity')->label(__('returns.quantity')),
            TextColumn::make('provisional_carrying_value')->label(__('returns.provisional_value'))
                ->visible(fn () => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST)),
            TextColumn::make('current_inspection_status')->label(__('returns.inspection_status'))
                ->state(fn (QuarantineReceipt $record): string => $record->inspections->isEmpty() ? 'pending_inspection' : 'awaiting_disposition_decision')
                ->formatStateUsing(fn ($s) => __('returns.'.$s)),
            TextColumn::make('inspections_count')->label(__('returns.inspection_observations')),
            TextColumn::make('inspections.observations')->label(__('returns.inspection_observations'))->listWithLineBreaks()->limit(200),
            TextColumn::make('inspections.inspectedBy.name')->label(__('returns.inspected_by'))->listWithLineBreaks(),
            TextColumn::make('condition_notes')->label(__('returns.condition_notes'))->wrap(),
            TextColumn::make('receivedBy.name')->label(__('returns.posted_by')),
            TextColumn::make('received_at')->label(__('returns.posted_at'))->dateTime(),
        ])->filters([SelectFilter::make('variant_id')->relationship('variant', 'sku')->label(__('returns.sku'))->searchable()])
            ->recordActions([])->toolbarActions([])->recordUrl(null)->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQuarantineReceipts::route('/')];
    }
}
