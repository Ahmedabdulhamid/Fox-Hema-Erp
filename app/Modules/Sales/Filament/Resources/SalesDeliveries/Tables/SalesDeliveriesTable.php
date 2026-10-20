<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Tables;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Sales\Filament\SalesDeliveryActions;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesDeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('delivery_number')->label(__('Delivery number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable()->copyable(),
            TextColumn::make('salesOrder.order_number')->label(__('Sales Order'))->searchable(),
            TextColumn::make('location.name')->label(__('Branch')),
            TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(ucfirst($state)))
                ->color(fn (string $state): string => match ($state) {
                    'posted' => 'success', 'cancelled' => 'danger', default => 'warning'
                }),
            TextColumn::make('items_sum_quantity')->sum('items', 'quantity')->label(__('Delivery quantity')),
            TextColumn::make('delivered_at')->label(__('Delivery date'))->dateTime()->sortable(),
            TextColumn::make('createdBy.name')->label(__('Created By'))->toggleable(),
            TextColumn::make('created_at')->label(__('Created at'))->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'posted' => __('Posted'), 'cancelled' => __('Cancelled')]),
            SelectFilter::make('location_id')->label(__('Branch'))->options(function (): array {
                $actor = auth()->user();
                if (! app(SalesAccess::class)->allows($actor, SalesPermissions::DELIVERIES_VIEW)) {
                    return [];
                }

                return Location::where('type', 'branch')->where('is_active', true)
                    ->when(! $actor->isGlobalSuperAdmin(), fn ($query) => $query->whereKey(getPermissionsTeamId()))->pluck('name', 'id')->all();
            }),
        ])->recordActions([ViewAction::make(), EditAction::make(), SalesDeliveryActions::post(), SalesDeliveryActions::cancel()])
            ->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }
}
