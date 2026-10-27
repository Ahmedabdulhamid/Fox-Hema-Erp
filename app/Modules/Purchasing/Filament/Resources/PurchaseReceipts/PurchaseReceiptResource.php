<?php

namespace App\Modules\Purchasing\Filament\Resources\PurchaseReceipts;

use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Schemas\PurchaseReceiptForm;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Schemas\PurchaseReceiptInfolist;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Tables\PurchaseReceiptsTable;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReceiptResource extends Resource
{
    protected static ?string $model = PurchaseReceipt::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Purchasing');
    }

    public static function getModelLabel(): string
    {
        return __('Goods Receipt');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Goods Receipts');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(PurchasingAccess::class)->scopeReceipts(parent::getEloquentQuery(), auth()->user())
            ->with(['items.purchaseOrderItem.variant.images', 'items.purchaseOrderItem.variant.product.brand', 'items.purchaseOrderItem.variant.product.images', 'purchaseOrder.supplier', 'location', 'items.purchaseOrderItem.variant.product',
                'items.purchaseOrderItem.variant.color', 'items.purchaseOrderItem.variant.size']);
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseReceiptForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseReceiptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseReceiptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseReceipts::route('/'), 'view' => ViewPurchaseReceipt::route('/{record}'),
            'edit' => EditPurchaseReceipt::route('/{record}/edit')];
    }
}
