<?php

namespace App\Modules\Products\Filament\Resources\Sizes;

use App\Modules\Products\Filament\Resources\Sizes\Pages\CreateSize;
use App\Modules\Products\Filament\Resources\Sizes\Pages\EditSize;
use App\Modules\Products\Filament\Resources\Sizes\Pages\ListSizes;
use App\Modules\Products\Filament\Resources\Sizes\Pages\ViewSize;
use App\Modules\Products\Filament\Resources\Sizes\Schemas\SizeForm;
use App\Modules\Products\Filament\Resources\Sizes\Schemas\SizeInfolist;
use App\Modules\Products\Filament\Resources\Sizes\Tables\SizesTable;
use App\Modules\Products\Models\Size;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SizeResource extends Resource
{
    protected static ?string $model = Size::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Size');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sizes');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return SizeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SizeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SizesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSizes::route('/'),
            'create' => CreateSize::route('/create'),
            'view' => ViewSize::route('/{record}'),
            'edit' => EditSize::route('/{record}/edit'),
        ];
    }
}
