<?php

namespace App\Modules\Purchasing\Filament\Resources\Suppliers;

use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Schemas\SupplierInfolist;
use App\Modules\Purchasing\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchasingAccess;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return __('Purchasing');
    }

    public static function getModelLabel(): string
    {
        return __('Supplier');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Suppliers');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return app(PurchasingAccess::class)->allows(auth()->user(), PurchasingPermissions::VIEW)
            ? $query : $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupplierInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'view' => ViewSupplier::route('/{record}'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
