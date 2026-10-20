<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers\Tables;

use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Livewire\Component;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->label(__('Supplier code'))->searchable()->sortable(),
            TextColumn::make('name')->label(__('Supplier name'))->searchable()->sortable(),
            TextColumn::make('contact')->label(__('Contact person'))->sortable()->placeholder('—'),
            TextColumn::make('phone')->label(__('Phone'))->placeholder('—'),
            TextColumn::make('email')->label(__('Email'))->placeholder('—')->toggleable(),
            TextColumn::make('payment_terms_days')->label(__('Payment terms (days)'))->sortable(),
            TextColumn::make('is_active')->label(__('Status'))->badge()->sortable()
                ->formatStateUsing(fn (bool $state): string => __($state ? 'Active' : 'Inactive'))
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->filters([TernaryFilter::make('is_active')->label(__('Active'))])
            ->defaultSort('name')->paginationPageOptions([25, 50, 100])
            ->recordActions([ViewAction::make(), EditAction::make(), ...self::statusActions()])
            ->toolbarActions([]);
    }

    /** @return list<Action> */
    public static function statusActions(): array
    {
        return [
            Action::make('activate')->label(__('Activate'))->icon('heroicon-o-check-circle')->color('success')
                ->authorize('activate')->visible(fn (Supplier $record): bool => ! $record->is_active)
                ->requiresConfirmation()->successNotificationTitle(__('Supplier activated.'))
                ->action(function (Supplier $record, Action $action, Component $livewire): void {
                    app(SupplierService::class)->activate(auth()->user(), $record);
                    $record->refresh();
                    if ($livewire instanceof EditRecord) {
                        $livewire->refreshFormData(['is_active']);
                    }
                    $action->success();
                }),
            Action::make('deactivate')->label(__('Deactivate'))->icon('heroicon-o-no-symbol')->color('warning')
                ->authorize('deactivate')->visible(fn (Supplier $record): bool => $record->is_active)
                ->requiresConfirmation()->successNotificationTitle(__('Supplier deactivated.'))
                ->action(function (Supplier $record, Action $action, Component $livewire): void {
                    app(SupplierService::class)->deactivate(auth()->user(), $record);
                    $record->refresh();
                    if ($livewire instanceof EditRecord) {
                        $livewire->refreshFormData(['is_active']);
                    }
                    $action->success();
                }),
        ];
    }
}
