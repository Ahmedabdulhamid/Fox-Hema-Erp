<?php

namespace App\Modules\AccessManagement\Filament\Resources\Permissions;

use App\Modules\AccessManagement\Models\Permission;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Users & Permissions');
    }

    public static function getModelLabel(): string
    {
        return __('Permission');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Permissions');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('permissions.guard_name', 'web')
            ->leftJoin('modules_permissions', 'permissions.id', '=', 'modules_permissions.permission_id')
            ->leftJoin('modules', 'modules.id', '=', 'modules_permissions.module_id')
            ->select('permissions.*')->selectRaw("COALESCE(modules.name, 'Unmapped historical permissions') as module_name");
    }

    public static function getRecordRouteKeyName(): string
    {
        return 'permissions.slug';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Permission overview'))->icon('heroicon-o-key')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Permission'))->formatStateUsing(fn (string $state): string => __($state))->weight('bold'),
                TextEntry::make('permission_code')->label(__('Permission code'))->state(fn (Permission $record): string => $record->name)->copyable()->fontFamily('mono'),
                TextEntry::make('guard_name')->label(__('Guard'))->badge(),
                TextEntry::make('module_name')->label(__('Module'))->formatStateUsing(fn (?string $state): string => __($state ?? 'Unmapped historical permissions'))->badge()->color('info'),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Permission'))->formatStateUsing(fn (string $state): string => __($state))->searchable(['permissions.name'])->sortable(['permissions.name']),
            TextColumn::make('module_name')->label(__('Module'))->formatStateUsing(fn (string $state): string => __($state)),
            TextColumn::make('guard_name')->label(__('Guard')),
        ])->defaultGroup(Group::make('module_name')->label(__('Module'))->getTitleFromRecordUsing(fn (Permission $record): string => __($record->module_name)))->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPermissions::route('/'), 'view' => Pages\ViewPermission::route('/{record}')];
    }
}
