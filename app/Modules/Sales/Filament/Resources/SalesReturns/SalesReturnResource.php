<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns;

use App\Modules\Sales\Filament\Resources\SalesReturns\Schemas\SalesReturnForm;
use App\Modules\Sales\Filament\Resources\SalesReturns\Schemas\SalesReturnInfolist;
use App\Modules\Sales\Filament\Resources\SalesReturns\Tables\SalesReturnsTable;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesReturnResource extends Resource
{
    protected static ?string $model = SalesReturn::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('returns.return');
    }

    public static function getPluralModelLabel(): string
    {
        return __('returns.returns');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesAccess::class)->scopeReturns(parent::getEloquentQuery(), auth()->user())
            ->with(['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'location', 'salesOrder', 'createdBy', 'approvedBy', 'cancelledBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return SalesReturnForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesReturnInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesReturnsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSalesReturns::route('/'), 'create' => Pages\CreateSalesReturn::route('/create'),
            'view' => Pages\ViewSalesReturn::route('/{record}'), 'edit' => Pages\EditSalesReturn::route('/{record}/edit')];
    }
}
