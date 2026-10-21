<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Tables;

use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class PurchaseOrdersTable
{
    public static function statuses(): array
    {
        return collect(['draft', 'rfq', 'confirmed', 'partially_received', 'received', 'closed', 'cancelled'])
            ->mapWithKeys(fn (string $status): array => [$status => __(ucwords(str_replace('_', ' ', $status)))])->all();
    }

    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label(__('PO number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable(),
            TextColumn::make('supplier.name')->label(__('Supplier'))->searchable()->sortable(),
            TextColumn::make('location.name')->label(__('Location'))->sortable(),
            TextColumn::make('order_date')->label(__('Order date'))->date()->sortable(),
            TextColumn::make('expected_date')->label(__('Expected delivery date'))->date()->sortable(),
            TextColumn::make('status')->label(__('Status'))->badge()->sortable()->formatStateUsing(fn (string $state): string => self::statuses()[$state] ?? $state),
            TextColumn::make('total_amount')->label(__('Total (EGP)'))->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('Status'))->options(self::statuses()),
            SelectFilter::make('supplier_id')->label(__('Supplier'))->searchable()->options(fn (): array => app(PurchaseOrderService::class)->suppliers(auth()->user(), false)->pluck('name', 'id')->all()),
            SelectFilter::make('location_id')->label(__('Location'))->options(fn (): array => app(PurchasingAccess::class)->locations(auth()->user())->pluck('name', 'id')->all()),
            self::dates(),
        ])->recordActions([ViewAction::make(), EditAction::make(), ...self::lifecycleActions()])
            ->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }

    public static function dates(string $column = 'order_date'): Filter
    {
        return Filter::make('order_dates')->label(__('Order dates'))->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('Until'))])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate($column, '<=', $date)));
    }

    public static function lifecycleActions(): array
    {
        $actions = [];
        foreach (['markRfq' => ['Mark RFQ', 'update', ['draft']], 'resetDraft' => ['Return to draft', 'update', ['rfq']],
            'confirm' => ['Confirm', 'confirm', ['draft', 'rfq']], 'cancel' => ['Cancel order', 'cancel', ['draft', 'rfq', 'confirmed']]] as $method => [$label, $permission, $statuses]) {
            $actions[] = Action::make($method)->label(__($label))->authorize($permission)
                ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, $statuses, true))
                ->requiresConfirmation()->modalDescription(__('Applies to saved document values. This action does not receive stock.'))
                ->schema([Hidden::make('revision')->default(fn (PurchaseOrder $record): string => app(PurchaseOrderService::class)->revision($record))])
                ->action(function (PurchaseOrder $record, array $data, Component $livewire) use ($method): void {
                    try {
                        app(PurchaseOrderService::class)->{$method}(auth()->user(), $record, $data['revision']);
                    } catch (ValidationException $exception) {
                        Notification::make()->title(__('Purchase order operation failed'))
                            ->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();

                        throw $exception;
                    }
                    $livewire->redirect(PurchaseOrderResource::getUrl('view', ['record' => $record]));
                });
        }

        return $actions;
    }
}
