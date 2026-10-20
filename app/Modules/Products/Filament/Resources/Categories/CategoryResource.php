<?php

namespace App\Modules\Products\Filament\Resources\Categories;

use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Filament\Resources\Categories\Pages\CreateCategory;
use App\Modules\Products\Filament\Resources\Categories\Pages\EditCategory;
use App\Modules\Products\Filament\Resources\Categories\Pages\ListCategories;
use App\Modules\Products\Filament\Resources\Categories\Pages\ViewCategory;
use App\Modules\Products\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Modules\Products\Filament\Resources\Categories\Schemas\CategoryInfolist;
use App\Modules\Products\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Modules\Products\Models\Category;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('parent')->withCount(['products', 'children']);
        if (app(CategoryCostingManager::class)->canView(auth()->user())) {
            $query->with('inventoryValuationSetting');
        }

        return $query;
    }

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Categories');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CategoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'view' => ViewCategory::route('/{record}'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
