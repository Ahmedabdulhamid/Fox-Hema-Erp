<?php

namespace App\Modules\Products\Filament\Resources\Brands;

use App\Modules\Products\Filament\Resources\Brands\Pages\CreateBrand;
use App\Modules\Products\Filament\Resources\Brands\Pages\EditBrand;
use App\Modules\Products\Filament\Resources\Brands\Pages\ListBrands;
use App\Modules\Products\Filament\Resources\Brands\Pages\ViewBrand;
use App\Modules\Products\Filament\Resources\Brands\Schemas\BrandForm;
use App\Modules\Products\Filament\Resources\Brands\Schemas\BrandInfolist;
use App\Modules\Products\Filament\Resources\Brands\Tables\BrandsTable;
use App\Modules\Products\Models\Brand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Brand');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Brands');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BrandInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
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
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'view' => ViewBrand::route('/{record}'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
