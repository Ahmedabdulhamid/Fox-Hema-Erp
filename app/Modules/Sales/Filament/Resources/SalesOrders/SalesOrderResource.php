<?php

namespace App\Modules\Sales\Filament\Resources\SalesOrders;

use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\CreateSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder;
use App\Modules\Sales\Filament\Resources\SalesOrders\Schemas\SalesOrderForm;
use App\Modules\Sales\Filament\Resources\SalesOrders\Schemas\SalesOrderInfolist;
use App\Modules\Sales\Filament\Resources\SalesOrders\Tables\SalesOrdersTable;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesOrderResource extends Resource
{
    protected static ?string $model = SalesOrder::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('Sales Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales Orders');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesAccess::class)->scopeOrders(parent::getEloquentQuery(), auth()->user())
            ->with(['items.salesOrder', 'items.variant.images', 'items.variant.product.images', 'location', 'createdBy', 'items.variant.product', 'items.variant.size', 'items.variant.color']);
    }

    public static function form(Schema $schema): Schema
    {
        return SalesOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesOrders::route('/'), 'create' => CreateSalesOrder::route('/create'),
            'view' => ViewSalesOrder::route('/{record}'), 'edit' => EditSalesOrder::route('/{record}/edit')];
    }
}
