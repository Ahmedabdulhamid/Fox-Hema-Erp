<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes;

use App\Modules\Taxes\Filament\Resources\Taxes\Pages\CreateTax;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\EditTax;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Modules\Taxes\Filament\Resources\Taxes\Pages\ViewTax;
use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxForm;
use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxInfolist;
use App\Modules\Taxes\Filament\Resources\Taxes\Tables\TaxesTable;
use App\Modules\Taxes\Models\Tax;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TaxResource extends Resource
{
    protected static ?string $model = Tax::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Tax');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Taxes');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return TaxForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaxInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxes::route('/'),
            'create' => CreateTax::route('/create'),
            'view' => ViewTax::route('/{record}'),
            'edit' => EditTax::route('/{record}/edit'),
        ];
    }
}
