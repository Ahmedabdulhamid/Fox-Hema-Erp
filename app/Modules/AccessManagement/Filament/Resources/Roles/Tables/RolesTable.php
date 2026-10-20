<?php

namespace App\Modules\AccessManagement\Filament\Resources\Roles\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
            TextColumn::make('scope')->label(__('Scope'))->state(fn ($record) => $record->location_id === null ? __('Global') : __('Location'))->badge(),
            TextColumn::make('location.name')->label(__('Location'))->placeholder(__('All locations'))->searchable(),
            TextColumn::make('permissions_count')->label(__('Permissions'))->sortable(),
            TextColumn::make('users_count')->label(__('Users'))->sortable(),
        ])->filters([
            SelectFilter::make('location_id')->label(__('Location'))->relationship('location', 'name'),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
        ])->defaultPaginationPageOption(25)->paginationPageOptions([10, 25, 50, 100]);
    }
}
