<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseOrders;

use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\PurchasePriceHistory;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Purchasing');
    }

    public static function getModelLabel(): string
    {
        return __('Purchase Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Purchase Orders');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(PurchasingAccess::class)->scopeOrders(parent::getEloquentQuery(), auth()->user())->with(['items.variant.images', 'items.variant.product.images', 'supplier', 'location']);
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseOrders::route('/'), 'create' => CreatePurchaseOrder::route('/create'),
            'history' => PurchasePriceHistory::route('/price-history'), 'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit')];
    }
}
