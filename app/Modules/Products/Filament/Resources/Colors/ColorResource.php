<?php

namespace App\Modules\Products\Filament\Resources\Colors;

use App\Modules\Products\Filament\Resources\Colors\Pages\CreateColor;
use App\Modules\Products\Filament\Resources\Colors\Pages\EditColor;
use App\Modules\Products\Filament\Resources\Colors\Pages\ListColors;
use App\Modules\Products\Filament\Resources\Colors\Pages\ViewColor;
use App\Modules\Products\Filament\Resources\Colors\Schemas\ColorForm;
use App\Modules\Products\Filament\Resources\Colors\Schemas\ColorInfolist;
use App\Modules\Products\Filament\Resources\Colors\Tables\ColorsTable;
use App\Modules\Products\Models\Color;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ColorResource extends Resource
{
    protected static ?string $model = Color::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Color');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Colors');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return ColorForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ColorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ColorsTable::configure($table);
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
            'index' => ListColors::route('/'),
            'create' => CreateColor::route('/create'),
            'view' => ViewColor::route('/{record}'),
            'edit' => EditColor::route('/{record}/edit'),
        ];
    }
}
