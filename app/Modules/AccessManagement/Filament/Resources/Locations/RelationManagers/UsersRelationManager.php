<?php

namespace App\Modules\AccessManagement\Filament\Resources\Locations\RelationManagers;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\UserLocationManager;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Users');
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
            ->modelLabel(__('User'))
            ->pluralModelLabel(__('Users'))
            ->inverseRelationship('locations')
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeQuery($query))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('last_login_at')->label(__('Last login'))->dateTime()->sortable(),
            ])
            ->headerActions([
                AttachAction::make()->preloadRecordSelect()->recordSelectSearchColumns(['name', 'email'])
                    ->authorize(fn (): bool => ! $this->isReadOnly() && $this->getOwnerRecord()->is_active && (auth()->user()->isGlobalSuperAdmin() || (auth()->user()->can('users.update') && auth()->user()->locations()->whereKey($this->getOwnerRecord())->exists())))
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->scopeQuery($query))
                    ->before(function (array $data): void {
                        $user = User::query()->findOrFail($data['recordId']);
                        abort_unless(app(UserLocationManager::class)->canManage($user, $this->getOwnerRecord()) && $this->getOwnerRecord()->is_active, 403);
                    })
                    ->using(function (User $record): void {
                        app(UserLocationManager::class)->attach($record, $this->getOwnerRecord());
                    }),
            ])
            ->recordActions([
                DetachAction::make()->requiresConfirmation()
                    ->authorize(fn (User $record): bool => ! $this->isReadOnly() && app(UserLocationManager::class)->canManage($record, $this->getOwnerRecord()))
                    ->using(fn (User $record): bool => app(UserLocationManager::class)->detach($record, $this->getOwnerRecord())),
            ])
            ->defaultSort('name')
            ->defaultPaginationPageOption(25);
    }

    private function scopeQuery(Builder $query): Builder
    {
        $actor = auth()->user();

        return $actor->isGlobalSuperAdmin() ? $query : $query->whereHas('locations', fn (Builder $query): Builder => $query->whereKey(getPermissionsTeamId()));
    }
}
