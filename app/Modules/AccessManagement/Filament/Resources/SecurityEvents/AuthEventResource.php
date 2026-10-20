<?php

namespace App\Modules\AccessManagement\Filament\Resources\SecurityEvents;

use App\Modules\AccessManagement\Filament\Resources\SecurityEvents\Pages\ListAuthEvents;
use App\Modules\AccessManagement\Filament\Resources\SecurityEvents\Pages\ViewAuthEvent;
use App\Modules\AccessManagement\Models\AuthEvent;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class AuthEventResource extends Resource
{
    protected static ?string $model = AuthEvent::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Security Event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Security Events');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Event overview'))
                ->icon('heroicon-o-shield-exclamation')->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])
                ->columns(2)
                ->schema([
                    TextEntry::make('event')->label(__('Event'))->formatStateUsing(fn (?string $state): string => __(Str::headline($state ?? 'Unknown event')))->badge()->color('primary'),
                    TextEntry::make('occurred_at')->label(__('Occurred at'))->dateTime()->placeholder(__('Not recorded')),
                    TextEntry::make('actor.name')->label(__('Performed by'))->placeholder(__('System')),
                    TextEntry::make('target.name')->label(__('Affected user'))->placeholder(__('Not specified')),
                    TextEntry::make('location.name')->label(__('Location'))->placeholder(__('Global')),
                    TextEntry::make('ip_address')->label(__('IP address'))->placeholder(__('Not recorded'))->fontFamily('mono')->copyable(),
                ]),
            Section::make(__('Request details'))
                ->icon('heroicon-o-computer-desktop')->columnSpanFull()
                ->schema([
                    TextEntry::make('user_agent')->label(__('Device / browser'))->placeholder(__('Not recorded'))->wrap(),
                ]),
            Section::make(__('Additional details'))
                ->icon('heroicon-o-document-text')->columnSpanFull()
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->label(__('Event details'))
                        ->keyLabel(__('Detail'))
                        ->valueLabel(__('Value'))
                        ->state(function (AuthEvent $record): array {
                            $metadata = $record->metadata ?? [];

                            if (array_key_exists('location_id', $metadata)) {
                                unset($metadata['location_id']);
                                $metadata[__('Location')] = $record->location?->name ?? __('Global');
                            }

                            return collect($metadata)->mapWithKeys(fn ($value, $key): array => [
                                __(Str::headline((string) $key)) => is_array($value)
                                    ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                    : $value,
                            ])->all();
                        }),
                ])
                ->visible(fn (AuthEvent $record): bool => filled($record->metadata)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('occurred_at')->label(__('Occurred at'))->dateTime()->sortable(), TextColumn::make('event')->label(__('Event'))->formatStateUsing(fn (string $state): string => __(Str::headline($state)))->badge()->searchable(), TextColumn::make('actor.name')->label(__('Performed by'))->placeholder(__('System')), TextColumn::make('target.name')->label(__('Affected user'))->placeholder(__('Unknown')), TextColumn::make('location.name')->label(__('Location'))->placeholder(__('Global')), TextColumn::make('ip_address')->label(__('IP address'))])->filters([SelectFilter::make('event')->label(__('Event'))->options(fn () => AuthEvent::query()->distinct()->orderBy('event')->pluck('event', 'event')->map(fn (string $event): string => __(Str::headline($event)))->all()), SelectFilter::make('location_id')->label(__('Location'))->relationship('location', 'name')])->recordActions([ViewAction::make()])->defaultSort('occurred_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['actor', 'target', 'location']);
        $user = auth()->user();

        return $user?->isGlobalSuperAdmin() ? $query : $query->where('location_id', getPermissionsTeamId());
    }

    public static function getPages(): array
    {
        return ['index' => ListAuthEvents::route('/'), 'view' => ViewAuthEvent::route('/{record}')];
    }
}
