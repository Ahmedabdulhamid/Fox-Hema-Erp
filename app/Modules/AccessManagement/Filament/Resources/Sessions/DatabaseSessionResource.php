<?php

namespace App\Modules\AccessManagement\Filament\Resources\Sessions;

use App\Modules\AccessManagement\Filament\Resources\Sessions\Pages\ListSessions;
use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Services\SessionManager;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DatabaseSessionResource extends Resource
{
    protected static ?string $model = DatabaseSession::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?int $navigationSort = 50;

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Session');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sessions');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('current')->label(__('Current'))->state(fn ($record) => request()->hasSession() && hash_equals(request()->session()->getId(), $record->id) ? __('Current') : '')->badge(),
            TextColumn::make('user.name')->label(__('User'))->searchable(),
            TextColumn::make('ip_address')->label(__('IP address')),
            TextColumn::make('user_agent')->label(__('Device / browser'))->limit(35)->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('login_at')->label(__('Login time'))->dateTime()->placeholder(__('Not recorded')),
            TextColumn::make('last_activity')->since()->label(__('Last activity')),
        ])->recordActions([
            Action::make('revoke')->label(__('Revoke session'))->color('danger')->requiresConfirmation()->authorize(fn ($record) => auth()->user()->can('delete', $record))->disabled(fn ($record) => request()->hasSession() && hash_equals(request()->session()->getId(), $record->id))->action(fn ($record) => app(SessionManager::class)->revoke($record->id, $record->user)),
        ])->defaultSort('last_activity', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with('user');
        $user = auth()->user();
        if ($user?->isGlobalSuperAdmin()) {
            return $q;
        }

        return $q->where(fn ($x) => $x->where('user_id', $user->id)->orWhereHas('user.locations', fn ($l) => $l->whereKey(getPermissionsTeamId())));
    }

    public static function getPages(): array
    {
        return ['index' => ListSessions::route('/')];
    }
}
