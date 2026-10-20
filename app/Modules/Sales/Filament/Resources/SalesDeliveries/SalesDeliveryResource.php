<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries;

use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\CreateSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\EditSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ListSalesDeliveries;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ViewSalesDelivery;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Schemas\SalesDeliveryForm;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Schemas\SalesDeliveryInfolist;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\Tables\SalesDeliveriesTable;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesDeliveryResource extends Resource
{
    protected static ?string $model = SalesDelivery::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('Sales Delivery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales Deliveries');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesAccess::class)->scopeDeliveries(parent::getEloquentQuery(), auth()->user())
            ->with(['items.salesOrderItem.variant.images', 'items.salesOrderItem.variant.product.images', 'location', 'salesOrder', 'createdBy', 'items.salesOrderItem.variant.product', 'items.salesOrderItem.variant.size', 'items.salesOrderItem.variant.color']);
    }

    public static function form(Schema $schema): Schema
    {
        return SalesDeliveryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesDeliveryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesDeliveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesDeliveries::route('/'), 'create' => CreateSalesDelivery::route('/create'),
            'view' => ViewSalesDelivery::route('/{record}'), 'edit' => EditSalesDelivery::route('/{record}/edit')];
    }
}
