<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts;

use App\Modules\Discounts\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\ListDiscounts;
use App\Modules\Discounts\Filament\Resources\Discounts\Pages\ViewDiscount;
use App\Modules\Discounts\Filament\Resources\Discounts\Schemas\DiscountForm;
use App\Modules\Discounts\Filament\Resources\Discounts\Schemas\DiscountInfolist;
use App\Modules\Discounts\Filament\Resources\Discounts\Tables\DiscountsTable;
use App\Modules\Discounts\Models\Discount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static ?string $recordRouteKeyName = 'slug';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Discount');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Discounts');
    }

    public static function getNavigationGroup(): string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return DiscountForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DiscountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiscountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiscounts::route('/'),
            'create' => CreateDiscount::route('/create'),
            'view' => ViewDiscount::route('/{record}'),
            'edit' => EditDiscount::route('/{record}/edit'),
        ];
    }
}
