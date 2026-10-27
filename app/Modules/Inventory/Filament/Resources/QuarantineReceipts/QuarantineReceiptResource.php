<?php

namespace App\Modules\Inventory\Filament\Resources\QuarantineReceipts;

use App\Models\QuantityFormatter;
use App\Modules\AccessManagement\Services\DocumentPresentation;
use App\Modules\Inventory\Filament\InventoryFilters;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryPermissions;
use Filament\Actions\Action;
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
            ->with(['variant.images', 'variant.product.images', 'variant.product.brand', 'variant.product', 'variant.color', 'variant.size', 'inspections.inspectedBy', 'location', 'allocation.salesReturn', 'receivedBy', 'inspections'])->withCount('inspections');
    }

    public static function table(Table $table): Table
    {
        return InventoryFilters::configure($table)->columns([
            TextColumn::make('display_reference')->label(__('returns.custody_reference'))->copyable()
                ->searchable(query: fn (Builder $query, string $search) => DocumentPresentation::searchNumber($query, $search)),
            TextColumn::make('location.name')->label(__('returns.branch')),
            TextColumn::make('variant.sku')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.sku'))->searchable(),
            TextColumn::make('variant.product.name')->formatStateUsing(fn ($state, $record) => view('components.inventory-product', ['variant' => $record->variant, 'title' => $state]))->label(__('returns.product'))->searchable(),
            TextColumn::make('allocation.salesReturn.return_number')->label(__('returns.return_number')),
            TextColumn::make('quantity')->label(__('returns.quantity'))->badge()->color('info')->formatStateUsing(fn ($state): string => QuantityFormatter::format($state)),
            TextColumn::make('provisional_carrying_value')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.provisional_value'))
                ->visible(fn () => app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::COST)),
            TextColumn::make('current_inspection_status')->label(__('returns.inspection_status'))
                ->state(fn (QuarantineReceipt $record): string => $record->inspections->isEmpty() ? 'pending_inspection' : 'awaiting_disposition_decision')
                ->formatStateUsing(fn (string $state): string => __('returns.'.$state))->badge()->color(fn (string $state): string => $state === 'pending_inspection' ? 'warning' : 'info'),
            TextColumn::make('inspections_count')->label(__('returns.inspection_observations')),
            TextColumn::make('inspections.observations')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.inspection_observations'))->listWithLineBreaks()->limit(200),
            TextColumn::make('inspections.inspectedBy.name')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.inspected_by'))->listWithLineBreaks(),
            TextColumn::make('condition_notes')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.condition_notes'))->wrap(),
            TextColumn::make('receivedBy.name')->toggleable(isToggledHiddenByDefault: true)->label(__('returns.posted_by')),
            TextColumn::make('received_at')->label(__('returns.posted_at'))->dateTime(),
        ])->filters([
            ...InventoryFilters::catalog(InventoryPermissions::QUARANTINE_VIEW, 'quarantine'),
            SelectFilter::make('inspection_status')->label(__('returns.inspection_status'))->options(['pending_inspection' => __('returns.pending_inspection'), 'awaiting_disposition_decision' => __('returns.awaiting_disposition_decision')])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'pending_inspection' => $query->doesntHave('inspections'), 'awaiting_disposition_decision' => $query->has('inspections'), default => $query
                }),
            InventoryFilters::dateRange('received_at', 'quarantine_receipts.received_at'),
        ])
            ->recordActions([
                Action::make('custodyDetails')->label(__('View Details'))->modal()->slideOver()->modalWidth('4xl')->modalHeading(__('Custody records'))->modalSubmitAction(false)
                    ->modalContent(fn (QuarantineReceipt $record) => view('inventory.custody-details', ['record' => $record])),
            ])->toolbarActions([])->recordUrl(null)->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQuarantineReceipts::route('/')];
    }
}
