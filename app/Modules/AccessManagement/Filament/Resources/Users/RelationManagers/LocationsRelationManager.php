<?php

namespace App\Modules\AccessManagement\Filament\Resources\Users\RelationManagers;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\UserLocationManager;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Locations');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
        parent::mount();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modelLabel(__('Location'))
            ->pluralModelLabel(__('Locations'))
            ->inverseRelationship('users')
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeQuery($query))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('code')->label(__('Code'))->searchable()->sortable(),
                TextColumn::make('type')->label(__('Type'))->formatStateUsing(fn (string $state): string => __(ucfirst($state)))->badge(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->headerActions([
                AttachAction::make()->preloadRecordSelect()->recordSelectSearchColumns(['name', 'code'])
                    ->authorize(fn (): bool => ! $this->isReadOnly() && auth()->user()->can('update', $this->getOwnerRecord()))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->scopeQuery($query)->where('locations.is_active', true))
                    ->before(function (array $data): void {
                        $location = Location::query()->findOrFail($data['recordId']);
                        abort_unless(app(UserLocationManager::class)->canManage($this->getOwnerRecord(), $location) && $location->is_active, 403);
                    })
                    ->using(function (Location $record): void {
                        app(UserLocationManager::class)->attach($this->getOwnerRecord(), $record);
                    }),
            ])
            ->recordActions([
                DetachAction::make()->requiresConfirmation()
                    ->authorize(fn (Location $record): bool => ! $this->isReadOnly() && app(UserLocationManager::class)->canManage($this->getOwnerRecord(), $record))
                    ->using(fn (Location $record): bool => app(UserLocationManager::class)->detach($this->getOwnerRecord(), $record)),
            ])
            ->defaultSort('name')
            ->defaultPaginationPageOption(25);
    }

    private function scopeQuery(Builder $query): Builder
    {
        $actor = auth()->user();

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereIn('locations.id', $actor->locations()->select('locations.id'));
    }
}
