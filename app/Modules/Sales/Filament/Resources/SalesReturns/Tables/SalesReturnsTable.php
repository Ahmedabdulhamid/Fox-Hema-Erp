<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Tables;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Sales\Filament\SalesReturnActions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('return_number')->label(__('returns.return_number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable(),
            TextColumn::make('salesOrder.order_number')->label(__('returns.order'))->searchable(),
            TextColumn::make('location.name')->label(__('returns.branch')),
            TextColumn::make('status')->label(__('returns.status'))->badge()->formatStateUsing(fn ($state) => __('returns.'.$state)),
            TextColumn::make('return_date')->label(__('returns.return_date'))->date()->sortable(),
            TextColumn::make('createdBy.name')->label(__('returns.created_by')),
            TextColumn::make('approvedBy.name')->label(__('returns.approved_by')),
            TextColumn::make('approved_at')->label(__('returns.approved_at'))->dateTime(),
        ])->filters([
            SelectFilter::make('status')->label(__('returns.status'))->options(collect(['draft', 'approved', 'posted', 'cancelled'])->mapWithKeys(fn ($s) => [$s => __('returns.'.$s)])->all()),
            SelectFilter::make('location_id')->label(__('returns.branch'))->options(fn () => Location::where('type', 'branch')->where('is_active', true)
                ->when(! auth()->user()?->isGlobalSuperAdmin(), fn ($q) => $q->whereKey(getPermissionsTeamId()))->pluck('name', 'id')->all()),
            Filter::make('return_date')->schema([DatePicker::make('from')->label(__('returns.from')), DatePicker::make('until')->label(__('returns.until'))])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('return_date', '>=', $date))
                    ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('return_date', '<=', $date))),
        ])->recordActions([ViewAction::make(), EditAction::make(), SalesReturnActions::approve(), SalesReturnActions::post(), SalesReturnActions::cancel()])
            ->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }
}
