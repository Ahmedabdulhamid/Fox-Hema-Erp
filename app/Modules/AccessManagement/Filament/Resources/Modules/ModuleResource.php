<?php

namespace App\Modules\AccessManagement\Filament\Resources\Modules;

use App\Modules\AccessManagement\Models\Module;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModuleResource extends Resource
{
    protected static ?string $model = Module::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Module');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Modules');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label(__('Code'))->disabled()->dehydrated(false),
            TextInput::make('name')->label(__('Name'))->formatStateUsing(fn (string $state): string => __($state))->disabled()->dehydrated(false),
            TextInput::make('sort_order')->label(__('Sort order'))->numeric()->integer()->minValue(0)->maxValue(4294967295)->required(),
            Toggle::make('is_active')->label(__('Enabled'))
                ->helperText(__('Inactive modules deny access to everyone, including Super Admins. Activation does not install required database tables.'))
                ->disabled(fn (Module $record): bool => $record->code === 'access_management'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Module overview'))->icon('heroicon-o-squares-2x2')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Name'))->formatStateUsing(fn (string $state): string => __($state))->weight('bold'),
                TextEntry::make('code')->label(__('Code'))->copyable()->fontFamily('mono'),
                TextEntry::make('is_active')->label(__('Status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Enabled') : __('Disabled')),
                TextEntry::make('sort_order')->label(__('Sort order')),
                TextEntry::make('description')->label(__('Description'))->formatStateUsing(fn (?string $state): string => __($state ?? 'Not set'))->columnSpanFull(),
            ]),
            Section::make(__('Permissions'))->icon('heroicon-o-key')->columnSpanFull()->schema([
                TextEntry::make('permissions.name')->hiddenLabel()->formatStateUsing(fn (string $state): string => __($state))->badge()->color('info')->placeholder(__('No implemented permissions')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name'))->formatStateUsing(fn (string $state): string => __($state))->searchable()->sortable(),
            TextColumn::make('code')->label(__('Code'))->searchable(),
            IconColumn::make('is_active')->boolean()->label(__('Enabled')),
            TextColumn::make('sort_order')->label(__('Sort order'))->sortable(),
            TextColumn::make('permissions_count')->counts('permissions')->label(__('Permissions')),
        ])->defaultSort('sort_order')->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListModules::route('/'), 'view' => Pages\ViewModule::route('/{record}'), 'edit' => Pages\EditModule::route('/{record}/edit')];
    }
}
