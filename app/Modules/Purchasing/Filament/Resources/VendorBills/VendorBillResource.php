<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorBills;

use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\CreateVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\EditVendorBill;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ListVendorBills;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchasingAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendorBillResource extends Resource
{
    protected static ?string $model = VendorBill::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Purchasing');
    }

    public static function getModelLabel(): string
    {
        return __('Vendor Bill');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vendor Bills');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(PurchasingAccess::class)->scopeBills(parent::getEloquentQuery(), auth()->user())
            ->with(['items.variant.images', 'items.variant.product.images', 'supplier', 'purchaseOrder.location', 'items.variant.product', 'items.receiptMatches.purchaseReceiptItem.purchaseReceipt',
                'items.receiptMatches.purchaseReceiptItem.purchaseOrderItem']);
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\VendorBillForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\VendorBillInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\VendorBillsTable::configure($table);
    }

    public static function actions(): array
    {
        return Tables\VendorBillsTable::actions();
    }

    public static function getPages(): array
    {
        return ['index' => ListVendorBills::route('/'), 'create' => CreateVendorBill::route('/create'),
            'view' => ViewVendorBill::route('/{record}'), 'edit' => EditVendorBill::route('/{record}/edit')];
    }
}
