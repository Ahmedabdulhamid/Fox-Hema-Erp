<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders\Tables;

use App\Modules\Sales\Filament\SalesOrderActions;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesOrdersTable
{
    public static function statuses(): array
    {
        return collect(['draft', 'confirmed', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'])
            ->mapWithKeys(fn (string $status): array => [$status => __(ucwords(str_replace('_', ' ', $status)))])->all();
    }

    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label(__('Order Number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable()->copyable(),
            TextColumn::make('location.name')->label(__('Branch'))->sortable(),
            TextColumn::make('customer_name')->label(__('Customer'))->searchable()->placeholder(__('Walk-in customer')),
            TextColumn::make('ordered_at')->label(__('Order date'))->dateTime()->sortable(),
            TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => self::statuses()[$state] ?? $state),
            TextColumn::make('fulfillment_status')->label(__('Fulfillment Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextColumn::make('total_amount')->label(__('Total (EGP)'))->sortable(),
            TextColumn::make('createdBy.name')->label(__('Created By'))->toggleable(),
        ])->filters([
            SelectFilter::make('location_id')->label(__('Branch'))->options(fn (): array => app(SalesAccess::class)->branches(auth()->user())->pluck('name', 'id')->all()),
            SelectFilter::make('status')->label(__('Status'))->options(self::statuses()),
            Filter::make('order_dates')->label(__('Order dates'))->schema([
                DatePicker::make('from')->label(__('From')),
                DatePicker::make('until')->label(__('Until'))->afterOrEqual('from'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('ordered_at', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('ordered_at', '<=', $date))),
        ])->recordActions([ViewAction::make(), EditAction::make(), ...SalesOrderActions::make()])->toolbarActions([])
            ->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }
}
